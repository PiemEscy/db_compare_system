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

$pair_id = $_GET['pair_id'] ?? null;
$tables = $_GET['tables'] ?? [];

if (!$pair_id) die("Missing pair_id.");
if (!is_array($tables)) $tables = [];
$tables = array_values(array_unique(array_filter($tables)));

if (count($tables) === 0) die("No tables selected.");

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

/**
 * =========================
 * STRUCTURE HELPERS
 * =========================
 */
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
            'constraint' => $row['CONSTRAINT_NAME'],
            'column' => $row['COLUMN_NAME'],
            'ref_db' => $row['REFERENCED_TABLE_SCHEMA'],
            'ref_table' => $row['REFERENCED_TABLE_NAME'],
            'ref_column' => $row['REFERENCED_COLUMN_NAME']
        ];
    }
    return $fks;
}

function normalizeFkSignature($fkCols) {
    usort($fkCols, function($a, $b){
        return strcmp($a['column'], $b['column']);
    });

    $parts = [];
    foreach ($fkCols as $fk) {
        $parts[] =
            ($fk['column'] ?? '') . '->' .
            ($fk['ref_table'] ?? '') . '(' .
            ($fk['ref_column'] ?? '') . ')';
    }
    return implode('|', $parts);
}

/**
 * =========================
 * BUILD BATCH DATA (NO SQL)
 * =========================
 */
$batch = [];

$totalTables = count($tables);
$tablesDifferent = 0;
$tablesSame = 0;

foreach ($tables as $table) {

    $colsFrom = getColumns($pdoFrom, $pair['db_from_name'], $table);
    $colsTo   = getColumns($pdoTo, $pair['db_to_name'], $table);

    $pkFrom = getPrimaryKeys($pdoFrom, $pair['db_from_name'], $table);
    $pkTo   = getPrimaryKeys($pdoTo, $pair['db_to_name'], $table);

    $uniqueFrom = getUniqueKeys($pdoFrom, $pair['db_from_name'], $table);
    $uniqueTo   = getUniqueKeys($pdoTo, $pair['db_to_name'], $table);

    $fkFrom = getForeignKeys($pdoFrom, $pair['db_from_name'], $table);
    $fkTo   = getForeignKeys($pdoTo, $pair['db_to_name'], $table);

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

    /**
     * =========================
     * PER TABLE SUMMARY COUNTS
     * =========================
     */
    $columnChanges = 0;
    foreach ($comparison as $colName => $cols) {
        $src = $cols['from'] ?? null;
        $dst = $cols['to'] ?? null;

        if (!$src || !$dst) {
            $columnChanges++;
            continue;
        }

        $srcDefault = $src['COLUMN_DEFAULT'] ?? '';
        $dstDefault = $dst['COLUMN_DEFAULT'] ?? '';

        if (
            $src['COLUMN_TYPE'] != $dst['COLUMN_TYPE'] ||
            $src['IS_NULLABLE'] != $dst['IS_NULLABLE'] ||
            $srcDefault != $dstDefault ||
            ($src['EXTRA'] ?? '') != ($dst['EXTRA'] ?? '')
        ) {
            $columnChanges++;
        }
    }

    $pkChanges = ($pkFrom == $pkTo) ? 0 : 1;

    $uniqueChanges = 0;
    $allUniqueNames = array_unique(array_merge(array_keys($uniqueFrom), array_keys($uniqueTo)));
    foreach ($allUniqueNames as $ukName) {
        $fromCols = $uniqueFrom[$ukName] ?? null;
        $toCols = $uniqueTo[$ukName] ?? null;

        if (!$fromCols || !$toCols) {
            $uniqueChanges++;
            continue;
        }

        if ($fromCols != $toCols) {
            $uniqueChanges++;
        }
    }

    $fkChanges = 0;
    $allFkNames = array_unique(array_merge(array_keys($fkFrom), array_keys($fkTo)));

    $fkFromSig = [];
    foreach ($fkFrom as $name => $fkCols) $fkFromSig[$name] = normalizeFkSignature($fkCols);

    $fkToSig = [];
    foreach ($fkTo as $name => $fkCols) $fkToSig[$name] = normalizeFkSignature($fkCols);

    foreach ($allFkNames as $fkName) {
        $from = $fkFromSig[$fkName] ?? null;
        $to = $fkToSig[$fkName] ?? null;

        if (!$from || !$to) {
            $fkChanges++;
            continue;
        }

        if ($from !== $to) {
            $fkChanges++;
        }
    }

    $isDifferent = ($columnChanges > 0) || ($pkChanges > 0) || ($uniqueChanges > 0) || ($fkChanges > 0);

    if ($isDifferent) $tablesDifferent++;
    else $tablesSame++;

    $batch[] = [
        'table' => $table,
        'comparison' => $comparison,
        'pkFrom' => $pkFrom,
        'pkTo' => $pkTo,
        'uniqueFrom' => $uniqueFrom,
        'uniqueTo' => $uniqueTo,
        'fkFrom' => $fkFrom,
        'fkTo' => $fkTo,
        'isDifferent' => $isDifferent,

        // summary stats
        'column_changes' => $columnChanges,
        'pk_changes' => $pkChanges,
        'unique_changes' => $uniqueChanges,
        'fk_changes' => $fkChanges,
    ];
}

// Build summary view
$summary = [];
foreach ($batch as $row) {
    $summary[] = [
        'table' => $row['table'],
        'different' => $row['isDifferent'],
        'column_changes' => $row['column_changes'],
        'pk_changes' => $row['pk_changes'],
        'unique_changes' => $row['unique_changes'],
        'fk_changes' => $row['fk_changes'],
    ];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Batch Structure Comparison</title>

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

<div class="max-w-7xl mx-auto px-6 py-8 space-y-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold">Batch Table Structure Comparison Documentation</h1>
            <p class="text-xs text-slate-600 mt-1">
                Title: <b><?= $pair['label'] ?></b> |
                Direction: <b><?= htmlspecialchars($pair['db_from_name']) ?> → <?= htmlspecialchars($pair['db_to_name']) ?></b>
            </p>
        </div>

        <div class="flex gap-2 print:hidden">
            <button onclick="window.print()"
                    class="rounded-xl bg-slate-900 text-white px-4 py-2 text-xs font-medium hover:bg-slate-800 shadow-sm">
                Print
            </button>
            <a href="table_comparison.php?pair_id=<?= $pair_id ?>"
               class="no-print inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 transition">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div id="toast-container" class="fixed top-5 right-5 space-y-2 z-50"></div>

    <!-- SUMMARY CARDS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="text-xs text-slate-500">Total Selected Tables</div>
            <div class="text-2xl font-bold mt-1"><?= (int)$totalTables ?></div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="text-xs text-slate-500">Tables Different</div>
            <div class="text-2xl font-bold mt-1 text-red-600"><?= (int)$tablesDifferent ?></div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="text-xs text-slate-500">Tables Same</div>
            <div class="text-2xl font-bold mt-1 text-emerald-600"><?= (int)$tablesSame ?></div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="text-xs text-slate-500">Compared Databases</div>
            <div class="text-sm font-semibold mt-2 text-slate-700">
                <?= htmlspecialchars($pair['db_from_name']) ?> → <?= htmlspecialchars($pair['db_to_name']) ?>
            </div>
        </div>
    </div>

    <!-- Summary View -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="font-semibold">Summary View</h2>
            <p class="text-xs text-slate-500">Quick status per table</p>
        </div>

        <div class="p-4 overflow-auto">
            <table class="w-full text-sm">
                <thead class="text-slate-600">
                    <tr class="border-b">
                        <th class="text-left py-2">Table</th>
                        <th class="text-left py-2">Status</th>
                        <th class="text-center py-2">Columns</th>
                        <th class="text-center py-2">PK</th>
                        <th class="text-center py-2">Unique</th>
                        <th class="text-center py-2">FK</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary as $s): ?>
                        <tr class="border-b">
                            <td class="py-2 font-medium">
                                <a href="#div-<?= htmlspecialchars($s['table']) ?>">
                                    <?= htmlspecialchars($s['table']) ?>
                                </a>
                            </td>
                            <td class="py-2">
                                <?php if($s['different']): ?>
                                    <span class="px-2 py-1 rounded-full bg-red-100 text-red-700 text-xs font-medium">Different</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 rounded-full bg-green-100 text-green-700 text-xs font-medium">Same</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 text-center"><?= (int)$s['column_changes'] ?></td>
                            <td class="py-2 text-center"><?= (int)$s['pk_changes'] ?></td>
                            <td class="py-2 text-center"><?= (int)$s['unique_changes'] ?></td>
                            <td class="py-2 text-center"><?= (int)$s['fk_changes'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- FULL STRUCTURE COMPARISON PER TABLE -->
    <?php foreach ($batch as $data): ?>
        <?php
            $table = $data['table'];
            $comparison = $data['comparison'];
            $pkFrom = $data['pkFrom'];
            $pkTo = $data['pkTo'];
            $uniqueFrom = $data['uniqueFrom'];
            $uniqueTo = $data['uniqueTo'];
            $fkFrom = $data['fkFrom'];
            $fkTo = $data['fkTo'];
        ?>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden" id="div-<?= htmlspecialchars($table) ?>">
            <div class="p-4 border-b flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-lg flex items-center gap-2">
                        <i class="fas fa-table text-slate-700"></i>
                        <?= htmlspecialchars($table) ?>

                        <?php if ($data['isDifferent']): ?>
                            <span class="text-xs px-2 py-1 rounded-full bg-red-100 text-red-700 font-medium">
                                Different
                            </span>
                        <?php else: ?>
                            <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700 font-medium">
                                Same
                            </span>
                        <?php endif; ?>
                    </h2>

                    <p class="text-xs text-slate-500">
                        Full structure comparison (Columns + PK + UNIQUE + FK)
                    </p>
                </div>

                <a href="structure_comparison.php?pair_id=<?= $pair_id ?>&table=<?= urlencode($table) ?>"
                   class="no-print text-xs text-slate-700 hover:text-slate-900 underline">
                    Open 1-by-1 view
                </a>
            </div>

            <!-- Comparison Table -->
            <div class="overflow-auto">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-4 py-1 text-left w-[20%]">Column</th>
                            <th class="px-4 py-1 text-left w-[40%]">DB From (<?= htmlspecialchars($pair['db_from_name']) ?>.<?= htmlspecialchars($table) ?>)</th>
                            <th class="px-4 py-1 text-left w-[40%]">DB To (<?= htmlspecialchars($pair['db_to_name']) ?>.<?= htmlspecialchars($table) ?>)</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($comparison as $colName => $cols): ?>
                            <?php
                                $diff = false;

                                if (!isset($cols['from']) || !isset($cols['to'])) {
                                    $diff = true;
                                } else {
                                    if (
                                        $cols['from']['COLUMN_TYPE'] != $cols['to']['COLUMN_TYPE'] ||
                                        $cols['from']['IS_NULLABLE'] != $cols['to']['IS_NULLABLE'] ||
                                        ($cols['from']['COLUMN_DEFAULT'] ?? '') != ($cols['to']['COLUMN_DEFAULT'] ?? '')
                                    ) {
                                        $diff = true;
                                    }

                                    $pkDiff = (in_array($colName, $pkFrom) xor in_array($colName, $pkTo));
                                    if ($pkDiff) $diff = true;

                                    $uniqueFromCols = [];
                                    foreach($uniqueFrom as $colsArr) $uniqueFromCols = array_merge($uniqueFromCols, $colsArr);
                                    $uniqueToCols = [];
                                    foreach($uniqueTo as $colsArr) $uniqueToCols = array_merge($uniqueToCols, $colsArr);
                                    if (in_array($colName, $uniqueFromCols) xor in_array($colName, $uniqueToCols)) $diff = true;

                                    $fkFromCols = [];
                                    foreach($fkFrom as $fkArr) foreach($fkArr as $fk) $fkFromCols[$fk['column']] = $fk['ref_table'].'('.$fk['ref_column'].')';
                                    $fkToCols = [];
                                    foreach($fkTo as $fkArr) foreach($fkArr as $fk) $fkToCols[$fk['column']] = $fk['ref_table'].'('.$fk['ref_column'].')';
                                    if (
                                        (isset($fkFromCols[$colName]) && !isset($fkToCols[$colName])) ||
                                        (!isset($fkFromCols[$colName]) && isset($fkToCols[$colName])) ||
                                        (isset($fkFromCols[$colName], $fkToCols[$colName]) && $fkFromCols[$colName] != $fkToCols[$colName])
                                    ) {
                                        $diff = true;
                                    }
                                }
                            ?>

                            <tr class="<?= $diff ? 'bg-red-100 text-red-900' : '' ?>">
                                <td class="px-4 py-1 font-medium text-xs">
                                    <i class="fas fa-columns text-slate-500 mr-1"></i>
                                    <?= htmlspecialchars($colName) ?>
                                </td>

                                <!-- FROM -->
                                <td class="px-4 py-1 font-mono text-xs">
                                    <?php if(isset($cols['from'])): ?>
                                        <?= "{$cols['from']['COLUMN_TYPE']} "
                                            . ($cols['from']['IS_NULLABLE']=='NO' ? 'NOT NULL' : 'NULL')
                                            . (isset($cols['from']['COLUMN_DEFAULT']) ? " DEFAULT '{$cols['from']['COLUMN_DEFAULT']}'" : '')
                                        ?>
                                    <?php else: ?>
                                        <span class="text-red-900">**Column not found**</span>
                                    <?php endif; ?>

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
                                                    echo '<span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-800 font-mono">FK → '.$fk['ref_table'].'('.$fk['ref_column'].'): '.$fk['constraint'].'</span>';
                                        } ?>
                                    </div>
                                </td>

                                <!-- TO -->
                                <td class="px-4 py-1 font-mono text-xs">
                                    <?php if(isset($cols['to'])): ?>
                                        <?= "{$cols['to']['COLUMN_TYPE']} "
                                            . ($cols['to']['IS_NULLABLE']=='NO' ? 'NOT NULL' : 'NULL')
                                            . (isset($cols['to']['COLUMN_DEFAULT']) ? " DEFAULT '{$cols['to']['COLUMN_DEFAULT']}'" : '')
                                        ?>
                                    <?php else: ?>
                                        <span class="text-red-900">**Column not found**</span>
                                    <?php endif; ?>

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
                                                    echo '<span class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-800 font-mono">FK → '.$fk['ref_table'].'('.$fk['ref_column'].'): '.$fk['constraint'].'</span>';
                                        } ?>
                                    </div>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>

                </table>
            </div>

        </div>

    <?php endforeach; ?>

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
</script>

<style>
@media print {
    .no-print { display: none !important; }
}
</style>

</body>
</html>
