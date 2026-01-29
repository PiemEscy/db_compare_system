<?php
require_once __DIR__ . '/../bootstrap.php';

// MAIN SYSTEM DB
$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbDatabase = $_ENV['DB_DATABASE'] ?? '';
$dbUsername = $_ENV['DB_USERNAME'] ?? 'root';
$dbPassword = $_ENV['DB_PASSWORD'] ?? '';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbDatabase};charset=utf8mb4";
try {
    $conn = new PDO($dsn, $dbUsername, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get selected pair & table
$pair_id = $_GET['pair_id'] ?? null;
$table   = $_GET['table'] ?? null;

if (!$pair_id || !$table) {
    die("Missing parameters.");
}

// Load selected pair
$stmt = $conn->prepare("SELECT * FROM db_connections WHERE id = :id");
$stmt->execute([':id' => $pair_id]);
$pair = $stmt->fetch();
if (!$pair) die("Connection pair not found.");

// Connect to DB FROM and DB TO
try {
    $pdoFrom = new PDO(
        "mysql:host={$pair['db_from_host']};port={$pair['db_from_port']};dbname={$pair['db_from_name']};charset=utf8mb4",
        $pair['db_from_user'], $pair['db_from_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdoTo = new PDO(
        "mysql:host={$pair['db_to_host']};port={$pair['db_to_port']};dbname={$pair['db_to_name']};charset=utf8mb4",
        $pair['db_to_user'], $pair['db_to_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("DB Connection error: " . $e->getMessage());
}

// Fetch structure
function getColumns(PDO $pdo, $dbName, $tableName) {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
        FROM information_schema.columns
        WHERE table_schema = :db AND table_name = :table
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([':db' => $dbName, ':table' => $tableName]);
    return $stmt->fetchAll();
}

function getPrimaryKeys(PDO $pdo, $dbName, $tableName) {
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE table_schema = :db AND table_name = :table AND CONSTRAINT_NAME='PRIMARY'
        ORDER BY ORDINAL_POSITION
    ");
    $stmt->execute([':db'=>$dbName, ':table'=>$tableName]);
    return array_column($stmt->fetchAll(), 'COLUMN_NAME');
}

function getUniqueKeys(PDO $pdo, $dbName, $tableName) {
    $stmt = $pdo->prepare("
        SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME
        FROM information_schema.TABLE_CONSTRAINTS t
        JOIN information_schema.KEY_COLUMN_USAGE k
          ON t.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND t.TABLE_SCHEMA = k.TABLE_SCHEMA
          AND t.TABLE_NAME = k.TABLE_NAME
        WHERE t.TABLE_SCHEMA = :db 
          AND t.TABLE_NAME = :table
          AND t.CONSTRAINT_TYPE = 'UNIQUE'
        ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION
    ");
    $stmt->execute([':db' => $dbName, ':table' => $tableName]);
    $keys = [];
    foreach($stmt->fetchAll() as $row){
        $keys[$row['CONSTRAINT_NAME']][] = $row['COLUMN_NAME'];
    }
    return $keys;
}


function getForeignKeys(PDO $pdo, $dbName, $tableName) {
    $stmt = $pdo->prepare("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE table_schema = :db AND table_name = :table AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $stmt->execute([':db'=>$dbName, ':table'=>$tableName]);
    $fks = [];
    foreach($stmt->fetchAll() as $row){
        $fks[$row['CONSTRAINT_NAME']][] = [
            'column' => $row['COLUMN_NAME'],
            'ref_db' => $row['REFERENCED_TABLE_SCHEMA'],
            'ref_table' => $row['REFERENCED_TABLE_NAME'],
            'ref_column' => $row['REFERENCED_COLUMN_NAME']
        ];
    }
    return $fks;
}


$colsFrom = getColumns($pdoFrom, $pair['db_from_name'], $table);
$colsTo   = getColumns($pdoTo, $pair['db_to_name'], $table);

$pkFrom = getPrimaryKeys($pdoFrom, $pair['db_from_name'], $table);
$pkTo   = getPrimaryKeys($pdoTo, $pair['db_to_name'], $table);

$uniqueFrom = getUniqueKeys($pdoFrom, $pair['db_from_name'], $table);
$uniqueTo   = getUniqueKeys($pdoTo, $pair['db_to_name'], $table);

$fkFrom = getForeignKeys($pdoFrom, $pair['db_from_name'], $table);
$fkTo   = getForeignKeys($pdoTo, $pair['db_to_name'], $table);

// Prepare comparison
$allColumns = array_unique(array_merge(
    array_column($colsFrom, 'COLUMN_NAME'),
    array_column($colsTo, 'COLUMN_NAME')
));

$comparison = [];
foreach ($allColumns as $col) {
    $fromCol = array_filter($colsFrom, fn($c)=>$c['COLUMN_NAME']==$col);
    $toCol   = array_filter($colsTo, fn($c)=>$c['COLUMN_NAME']==$col);
    $comparison[$col] = [
        'from' => $fromCol ? array_values($fromCol)[0] : null,
        'to'   => $toCol ? array_values($toCol)[0] : null
    ];
}

// Generate SQL commands
function generateAlterSQL($comparison, $table, $fromName, $toName, $pkFrom, $pkTo, $uniqueFrom, $uniqueTo, $fkFrom, $fkTo) {
    $sqls = [];

    $sqls[] = "-- Command to sync table `$table` from `$fromName` → `$toName`";
    // Columns
    foreach ($comparison as $colName => $cols) {
        $src = $cols['from'] ?? null;
        $dst = $cols['to'] ?? null;

        if (!$src) continue;

        if (!$dst) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` ADD COLUMN `{$src['COLUMN_NAME']}` {$src['COLUMN_TYPE']} "
                      . ($src['IS_NULLABLE']=='NO'?'NOT NULL':'NULL')
                      . ($src['COLUMN_DEFAULT']!==null ? " DEFAULT '{$src['COLUMN_DEFAULT']}'":"")
                      . ($src['EXTRA'] ? " {$src['EXTRA']}" : "")
                      . ";";
        } elseif ($dst && ($src['COLUMN_TYPE'] != $dst['COLUMN_TYPE'] 
                          || $src['IS_NULLABLE'] != $dst['IS_NULLABLE'] 
                          || ($src['COLUMN_DEFAULT']??'') != ($dst['COLUMN_DEFAULT']??''))) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` MODIFY COLUMN `{$src['COLUMN_NAME']}` {$src['COLUMN_TYPE']} "
                      . ($src['IS_NULLABLE']=='NO'?'NOT NULL':'NULL')
                      . ($src['COLUMN_DEFAULT']!==null ? " DEFAULT '{$src['COLUMN_DEFAULT']}'":"")
                      . ($src['EXTRA'] ? " {$src['EXTRA']}" : "")
                      . ";";
        }
    }

    // Primary Key
    if ($pkFrom != $pkTo) {
        if (!empty($pkTo)) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` DROP PRIMARY KEY;";
        }
        if (!empty($pkFrom)) {
            $cols = implode('`,`', $pkFrom);
            $sqls[] = "ALTER TABLE `$toName`.`$table` ADD PRIMARY KEY (`$cols`);";
        }
    }

    // Unique Keys
    foreach($uniqueFrom as $name => $cols) {
        if (!isset($uniqueTo[$name])) {
            $colsStr = implode('`,`', $cols);
            $sqls[] = "ALTER TABLE `$toName`.`$table` ADD UNIQUE `$name` (`$colsStr`);";
        }
    }

    // Foreign Keys
    foreach($fkFrom as $name => $fkCols) {
        if (!isset($fkTo[$name])) {
            $columns = [];
            $refColumns = [];
            $refDb = $fkCols[0]['ref_db'] ?? $toName; // use first FK's DB
            $refTable = $fkCols[0]['ref_table'] ?? $table;
            foreach($fkCols as $fk) {
                $columns[] = "`{$fk['column']}`";
                $refColumns[] = "`{$fk['ref_column']}`";
            }
            $colsStr = implode(',', $columns);
            $refColsStr = implode(',', $refColumns);
            $sqls[] = "ALTER TABLE `$toName`.`$table` ADD CONSTRAINT `$name` FOREIGN KEY ($colsStr) REFERENCES `$refDb`.`$refTable`($refColsStr);";
        }
    }


    return implode("\n", $sqls);
}


$sqlFromTo = generateAlterSQL($comparison, $table, $pair['db_from_name'], $pair['db_to_name'], $pkFrom, $pkTo, $uniqueFrom, $uniqueTo, $fkFrom, $fkTo);

// For To → From, swap the roles
$comparisonRev = [];
foreach($comparison as $col => $cols) {
    $comparisonRev[$col] = ['from' => $cols['to'] ?? null, 'to' => $cols['from'] ?? null];
}

$sqlToFrom = generateAlterSQL($comparisonRev, $table, $pair['db_to_name'], $pair['db_from_name'], $pkTo, $pkFrom, $uniqueTo, $uniqueFrom, $fkTo, $fkFrom);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Structure Comparison - <?= htmlspecialchars($table) ?></title>

<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
    @keyframes fade-in { from { opacity: 0; transform: translateY(-10px);} to {opacity:1; transform: translateY(0);} }
    @keyframes fade-out { from { opacity: 1; transform: translateY(0);} to {opacity:0; transform: translateY(-10px);} }
    .animate-fade-in { animation: fade-in 0.3s ease forwards; }
.animate-fade-out { animation: fade-out 0.5s ease forwards; }
</style>
</head>
<body class="bg-gray-50">

<div class="max-w-8xl mx-auto px-6 py-8 space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-semibold flex items-center gap-2">
            <i class="fas fa-code-branch text-slate-700"></i> Structure Comparison: <?= htmlspecialchars($table) ?>
        </h1>
        <a href="table_comparison.php?pair_id=<?= $pair_id ?>" 
           class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 transition">
           <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <div id="toast-container" class="fixed top-5 right-5 space-y-2 z-50"></div>

    <!-- SQL Commands -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- FROM → TO -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold text-sm flex items-center gap-1">
                    <i class="fas fa-arrow-right text-green-500"></i> SQL: From → To (<?= $pair['db_from_name'] ?> → <?= $pair['db_to_name'] ?>)
                </h2>
                <div class="flex gap-2">
                    <button class="copy-btn text-xs text-slate-700 hover:text-slate-900" data-target="sqlFromTo">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                    <button class="run-btn text-xs bg-green-600 text-white px-2 py-1 rounded hover:bg-green-500"
                        data-direction="from_to" data-target="sqlFromTo">
                        Run
                    </button>
                </div>
            </div>
            <pre id="sqlFromTo" class="text-xs font-mono bg-slate-100 p-2 rounded max-h-72 overflow-auto"><?= htmlspecialchars($sqlFromTo) ?></pre>
        </div>

        <!-- TO → FROM -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="font-semibold text-sm flex items-center gap-1">
                    <i class="fas fa-arrow-left text-purple-500"></i> SQL: To → From (<?= $pair['db_to_name'] ?> → <?= $pair['db_from_name'] ?>)
                </h2>
                <div class="flex gap-2">
                    <button class="copy-btn text-xs text-slate-700 hover:text-slate-900" data-target="sqlToFrom">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                    <button class="run-btn text-xs bg-purple-600 text-white px-2 py-1 rounded hover:bg-purple-500"
                        data-direction="to_from" data-target="sqlToFrom">
                        Run
                    </button>
                </div>
            </div>
            <pre id="sqlToFrom" class="text-xs font-mono bg-slate-100 p-2 rounded max-h-72 overflow-auto"><?= htmlspecialchars($sqlToFrom) ?></pre>
        </div>

    </div>


    <!-- Comparison Table -->
    <div class="overflow-auto bg-white rounded-2xl border border-slate-200 shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-100">
                <tr>
                    <th class="px-4 py-2 text-left">Column</th>
                    <th class="px-4 py-2 text-left">DB From (<?= htmlspecialchars($pair['db_from_name']) ?>)</th>
                    <th class="px-4 py-2 text-left">DB To (<?= htmlspecialchars($pair['db_to_name']) ?>)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php foreach ($comparison as $colName => $cols): ?>
                    <?php
                        $diff = false;
                        $missing = false;

                        // Check if column is missing in either table
                        if (!isset($cols['from']) || !isset($cols['to'])) {
                            $diff = true;
                            $missing = true;
                        } else {
                            // Column exists in both → check type/default/nullability
                            if (
                                $cols['from']['COLUMN_TYPE'] != $cols['to']['COLUMN_TYPE'] ||
                                $cols['from']['IS_NULLABLE'] != $cols['to']['IS_NULLABLE'] ||
                                ($cols['from']['COLUMN_DEFAULT'] ?? '') != ($cols['to']['COLUMN_DEFAULT'] ?? '')
                            ) {
                                $diff = true;
                            }

                            // Check PK differences
                            $pkDiff = (in_array($colName, $pkFrom) xor in_array($colName, $pkTo));
                            if ($pkDiff) $diff = true;

                            // Check UNIQUE differences
                            $uniqueFromCols = [];
                            foreach($uniqueFrom as $colsArr) $uniqueFromCols = array_merge($uniqueFromCols, $colsArr);
                            $uniqueToCols = [];
                            foreach($uniqueTo as $colsArr) $uniqueToCols = array_merge($uniqueToCols, $colsArr);
                            if (in_array($colName, $uniqueFromCols) xor in_array($colName, $uniqueToCols)) $diff = true;

                            // Check FK differences
                            $fkFromCols = [];
                            foreach($fkFrom as $fkArr) foreach($fkArr as $fk) $fkFromCols[$fk['column']] = $fk['ref_table'].'('.$fk['ref_column'].')';
                            $fkToCols = [];
                            foreach($fkTo as $fkArr) foreach($fkArr as $fk) $fkToCols[$fk['column']] = $fk['ref_table'].'('.$fk['ref_column'].')';
                            if ((isset($fkFromCols[$colName]) && !isset($fkToCols[$colName])) || (!isset($fkFromCols[$colName]) && isset($fkToCols[$colName])) || (isset($fkFromCols[$colName], $fkToCols[$colName]) && $fkFromCols[$colName] != $fkToCols[$colName])) {
                                $diff = true;
                            }
                        }
                    ?>

                    <tr class="<?= $diff ? 'bg-red-50' : '' ?>">
                        <td class="px-4 py-2 font-medium">
                            <i class="fas fa-table text-slate-500 mr-1"></i> <?= htmlspecialchars($colName) ?>
                        </td>

                        <!-- FROM DB -->
                        <td class="px-4 py-2 font-mono text-xs">
                            <?php if(isset($cols['from'])): ?>
                                <?= "{$cols['from']['COLUMN_TYPE']} "
                                    . ($cols['from']['IS_NULLABLE']=='NO' ? 'NOT NULL' : 'NULL')
                                    . (isset($cols['from']['COLUMN_DEFAULT']) ? " DEFAULT '{$cols['from']['COLUMN_DEFAULT']}'" : '') 
                                ?>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>

                            <!-- FROM badges -->
                            <div class="flex flex-wrap gap-1 mt-1 text-xs">
                                <?php if (in_array($colName, $pkFrom)) : ?>
                                    <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-800 font-mono">PK</span>
                                <?php endif; ?>
                                <?php foreach($uniqueFrom as $name => $uniqueCols) {
                                    if (in_array($colName, $uniqueCols))
                                        echo '<span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 font-mono">UNIQUE</span>';
                                } ?>
                                <?php foreach($fkFrom as $name => $fkCols) {
                                    foreach($fkCols as $fk)
                                        if ($fk['column']==$colName)
                                            echo '<span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 font-mono">FK → '.$fk['ref_table'].'('.$fk['ref_column'].')</span>';
                                } ?>
                            </div>
                        </td>

                        <!-- TO DB -->
                        <td class="px-4 py-2 font-mono text-xs">
                            <?php if(isset($cols['to'])): ?>
                                <?= "{$cols['to']['COLUMN_TYPE']} "
                                    . ($cols['to']['IS_NULLABLE']=='NO' ? 'NOT NULL' : 'NULL')
                                    . (isset($cols['to']['COLUMN_DEFAULT']) ? " DEFAULT '{$cols['to']['COLUMN_DEFAULT']}'" : '') 
                                ?>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>

                            <!-- TO badges -->
                            <div class="flex flex-wrap gap-1 mt-1 text-xs">
                                <?php if (in_array($colName, $pkTo)) : ?>
                                    <span class="px-2 py-0.5 rounded-full bg-green-50 text-green-800 font-mono">PK</span>
                                <?php endif; ?>
                                <?php foreach($uniqueTo as $name => $uniqueCols) {
                                    if (in_array($colName, $uniqueCols))
                                        echo '<span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-800 font-mono">UNIQUE</span>';
                                } ?>
                                <?php foreach($fkTo as $name => $fkCols) {
                                    foreach($fkCols as $fk)
                                        if ($fk['column']==$colName)
                                            echo '<span class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-800 font-mono">FK → '.$fk['ref_table'].'('.$fk['ref_column'].')</span>';
                                } ?>
                            </div>
                        </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<script>

    function showToast(message, type='success', duration=3000) {
        const container = document.getElementById('toast-container');
        const colors = {
            success: 'bg-green-600 text-white',
            error: 'bg-red-600 text-white',
            info: 'bg-slate-700 text-white'
        };
        const toast = document.createElement('div');
        toast.className = `px-4 py-2 rounded shadow ${colors[type]} text-sm animate-fade-in`;
        toast.innerText = message;
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('animate-fade-out');
            setTimeout(() => toast.remove(), 500);
        }, duration);
    }

    function fallbackCopy(text, btn) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            showToast('Copied!');
        } catch (err) {
            alert('Copy failed: ' + err);
        }
        document.body.removeChild(textarea);
    }


    // COPY FUNCTIONALITY
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const el = document.getElementById(targetId);

            if (!el) return alert('Target element not found: ' + targetId);

            const text = el.innerText;

            // Modern Clipboard API
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text)
                    .then(() => showToast(btn, 'Copied!'))
                    .catch(() => fallbackCopy(text, btn));
            } else {
                fallbackCopy(text, btn);
            }
        });
    });

    // RUN SQL FUNCTIONALITY
    document.querySelectorAll('.run-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const direction = btn.dataset.direction;
            const targetId = btn.dataset.target;
            const sqlText = document.getElementById(targetId).innerText;

            if (!confirm("Are you sure you want to run this SQL? This may modify the database!")) return;

            fetch('action/execute_missing_tables.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    pair_id: <?= json_encode($pair_id) ?>,
                    direction,
                    sql: sqlText
                })
            })
            .then(res => res.json())
            .then(data => {
                if(data.success){
                    showToast('SQL executed successfully!', 'success');
                    
                    // Optionally refresh page to show updated table structure
                    setTimeout(() => location.reload(), 1000); // reload after 1s
                } else {
                    showToast('Error executing SQL: ' + data.error, 'error', 5000);
                }
            })
            .catch(err => showToast('Request failed: ' + err, 'error', 5000));
        });
    });

</script>

</body>
</html>
