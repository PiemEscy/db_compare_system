<?php
require_once __DIR__ . '/../bootstrap.php';

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

$pair_id   = $_GET['pair_id'] ?? null;
$direction = $_GET['direction'] ?? null;

if (!$pair_id || !in_array($direction, ['from_to', 'to_from'])) {
    die("Missing or invalid parameters.");
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

// ============================================================
// FUNCTIONS
// ============================================================

function getTables(PDO $pdo, string $dbName): array
{
    $stmt = $pdo->prepare("
        SELECT TABLE_NAME
        FROM information_schema.tables
        WHERE table_schema = :db
        ORDER BY TABLE_NAME
    ");
    $stmt->execute([':db' => $dbName]);
    return array_column($stmt->fetchAll(), 'TABLE_NAME');
}

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
        ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION
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
            // ($fk['ref_db'] ?? '') . '.' .
            ($fk['ref_table'] ?? '') . '(' .
            ($fk['ref_column'] ?? '') . ')';
    }

    return implode('|', $parts);
}

function generateAlterSQL(
    $comparison,
    $table,
    $fromName,
    $toName,
    $pkFrom,
    $pkTo,
    $uniqueFrom,
    $uniqueTo,
    $fkFrom,
    $fkTo,
    &$tableStats // pass by reference to collect summary per table
) {
    $sqls = [];

    $tableStats = [
        'table' => $table,
        'column_changes' => 0,
        'pk_changes' => 0,
        'unique_changes' => 0,
        'fk_changes' => 0,
        'sql_count' => 0,
    ];

    $sqls[] = "-- Command to sync table `$table` from `$fromName` → `$toName`";

    /**
     * 1) COLUMNS
     */
    foreach ($comparison as $colName => $cols) {
        $src = $cols['from'] ?? null;
        $dst = $cols['to'] ?? null;

        if (!$src) continue;

        // ADD COLUMN
        if (!$dst) {
            $sqls[] =
                "ALTER TABLE `$toName`.`$table` ADD COLUMN `{$src['COLUMN_NAME']}` {$src['COLUMN_TYPE']} " .
                ($src['IS_NULLABLE'] == 'NO' ? 'NOT NULL' : 'NULL') .
                ($src['COLUMN_DEFAULT'] !== null ? " DEFAULT " . $GLOBALS['conn']->quote($src['COLUMN_DEFAULT']) : "") .
                ($src['EXTRA'] ? " {$src['EXTRA']}" : "") .
                ";";

            $tableStats['column_changes']++;
            continue;
        }

        // MODIFY COLUMN
        $srcDefault = $src['COLUMN_DEFAULT'] ?? '';
        $dstDefault = $dst['COLUMN_DEFAULT'] ?? '';

        if (
            $src['COLUMN_TYPE'] != $dst['COLUMN_TYPE'] ||
            $src['IS_NULLABLE'] != $dst['IS_NULLABLE'] ||
            $srcDefault != $dstDefault ||
            ($src['EXTRA'] ?? '') != ($dst['EXTRA'] ?? '')
        ) {
            $sqls[] =
                "ALTER TABLE `$toName`.`$table` MODIFY COLUMN `{$src['COLUMN_NAME']}` {$src['COLUMN_TYPE']} " .
                ($src['IS_NULLABLE'] == 'NO' ? 'NOT NULL' : 'NULL') .
                ($src['COLUMN_DEFAULT'] !== null ? " DEFAULT " . $GLOBALS['conn']->quote($src['COLUMN_DEFAULT']) : "") .
                ($src['EXTRA'] ? " {$src['EXTRA']}" : "") .
                ";";

            $tableStats['column_changes']++;
        }
    }

    /**
     * 2) PRIMARY KEY
     */
    if ($pkFrom != $pkTo) {
        $tableStats['pk_changes']++;

        if (!empty($pkTo)) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` DROP PRIMARY KEY;";
        }
        if (!empty($pkFrom)) {
            $cols = implode('`,`', $pkFrom);
            $sqls[] = "ALTER TABLE `$toName`.`$table` ADD PRIMARY KEY (`$cols`);";
        }
    }

    /**
     * 3) UNIQUE KEYS
     */
    foreach ($uniqueFrom as $name => $cols) {
        if (!isset($uniqueTo[$name])) {
            $colsStr = implode('`,`', $cols);
            $sqls[] = "ALTER TABLE `$toName`.`$table` ADD UNIQUE `$name` (`$colsStr`);";
            $tableStats['unique_changes']++;
        } else {
            if ($uniqueFrom[$name] != $uniqueTo[$name]) {
                $sqls[] = "ALTER TABLE `$toName`.`$table` DROP INDEX `$name`;";
                $colsStr = implode('`,`', $uniqueFrom[$name]);
                $sqls[] = "ALTER TABLE `$toName`.`$table` ADD UNIQUE `$name` (`$colsStr`);";
                $tableStats['unique_changes']++;
            }
        }
    }

    foreach ($uniqueTo as $name => $cols) {
        if (!isset($uniqueFrom[$name])) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` DROP INDEX `$name`;";
            $tableStats['unique_changes']++;
        }
    }

    /**
     * 4) FOREIGN KEYS
     */
    $fkFromSig = [];
    foreach ($fkFrom as $name => $fkCols) {
        $fkFromSig[$name] = normalizeFkSignature($fkCols);
    }

    $fkToSig = [];
    foreach ($fkTo as $name => $fkCols) {
        $fkToSig[$name] = normalizeFkSignature($fkCols);
    }

    foreach ($fkFrom as $name => $fkCols) {

        $needsAdd = !isset($fkTo[$name]);
        $needsRecreate = isset($fkTo[$name]) && ($fkFromSig[$name] !== $fkToSig[$name]);

        if ($needsRecreate) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` DROP FOREIGN KEY `$name`;";
            $tableStats['fk_changes']++;
        }

        if ($needsAdd || $needsRecreate) {
            $columns = [];
            $refColumns = [];

            $refDb = $fkCols[0]['ref_db'] ?? $fromName;
            $refTable = $fkCols[0]['ref_table'] ?? null;

            foreach ($fkCols as $fk) {
                $columns[] = "`{$fk['column']}`";
                $refColumns[] = "`{$fk['ref_column']}`";
            }

            $colsStr = implode(',', $columns);
            $refColsStr = implode(',', $refColumns);

            $sqls[] =
                "ALTER TABLE `$toName`.`$table` " .
                "ADD CONSTRAINT `$name` FOREIGN KEY ($colsStr) " .
                "REFERENCES `$refDb`.`$refTable`($refColsStr);";

            $tableStats['fk_changes']++;
        }
    }

    foreach ($fkTo as $name => $fkCols) {
        if (!isset($fkFrom[$name])) {
            $sqls[] = "ALTER TABLE `$toName`.`$table` DROP FOREIGN KEY `$name`;";
            $tableStats['fk_changes']++;
        }
    }

    $tableStats['sql_count'] = count($sqls);

    return implode("\n", $sqls);
}

// ============================================================
// DOCUMENT GENERATION
// ============================================================

$fromDb = $pair['db_from_name'];
$toDb   = $pair['db_to_name'];

$tablesFrom = getTables($pdoFrom, $fromDb);
$tablesTo   = getTables($pdoTo, $toDb);

$commonTables = array_values(array_intersect($tablesFrom, $tablesTo));

$allSqlOutput = [];
$tableSummaries = [];

$totalSqlCommands = 0;
$tablesChanged = 0;
$tablesNoChanges = 0;

foreach ($commonTables as $table) {

    // FROM side
    $colsFrom = getColumns($pdoFrom, $fromDb, $table);
    $pkFrom   = getPrimaryKeys($pdoFrom, $fromDb, $table);
    $uniqueFrom = getUniqueKeys($pdoFrom, $fromDb, $table);
    $fkFrom   = getForeignKeys($pdoFrom, $fromDb, $table);

    // TO side
    $colsTo = getColumns($pdoTo, $toDb, $table);
    $pkTo   = getPrimaryKeys($pdoTo, $toDb, $table);
    $uniqueTo = getUniqueKeys($pdoTo, $toDb, $table);
    $fkTo   = getForeignKeys($pdoTo, $toDb, $table);

    // Prepare comparison
    $allColumns = array_unique(array_merge(
        array_column($colsFrom, 'COLUMN_NAME'),
        array_column($colsTo, 'COLUMN_NAME')
    ));

    $comparison = [];
    foreach ($allColumns as $col) {
        $fromCol = array_values(array_filter($colsFrom, fn($c)=>$c['COLUMN_NAME']==$col));
        $toCol   = array_values(array_filter($colsTo, fn($c)=>$c['COLUMN_NAME']==$col));

        $comparison[$col] = [
            'from' => $fromCol[0] ?? null,
            'to'   => $toCol[0] ?? null
        ];
    }

    // Direction swap
    if ($direction === 'from_to') {
        $tableStats = [];
        $sql = generateAlterSQL(
            $comparison,
            $table,
            $fromDb,
            $toDb,
            $pkFrom,
            $pkTo,
            $uniqueFrom,
            $uniqueTo,
            $fkFrom,
            $fkTo,
            $tableStats
        );
    } else {
        $comparisonRev = [];
        foreach($comparison as $col => $cols) {
            $comparisonRev[$col] = [
                'from' => $cols['to'] ?? null,
                'to'   => $cols['from'] ?? null
            ];
        }

        $tableStats = [];
        $sql = generateAlterSQL(
            $comparisonRev,
            $table,
            $toDb,
            $fromDb,
            $pkTo,
            $pkFrom,
            $uniqueTo,
            $uniqueFrom,
            $fkTo,
            $fkFrom,
            $tableStats
        );
    }

    // detect if changed (any sql beyond the header comment)
    $sqlLines = array_filter(explode("\n", $sql), fn($line) => trim($line) !== '');
    $hasChanges = count($sqlLines) > 1;

    if ($hasChanges) $tablesChanged++;
    else $tablesNoChanges++;

    $tableSummaries[] = [
        'table' => $table,
        'changed' => $hasChanges,
        'column_changes' => $tableStats['column_changes'],
        'pk_changes' => $tableStats['pk_changes'],
        'unique_changes' => $tableStats['unique_changes'],
        'fk_changes' => $tableStats['fk_changes'],
        'sql_count' => $tableStats['sql_count'],
    ];

    if ($hasChanges) {
        $allSqlOutput[] = $sql;
        $totalSqlCommands += (((int) $tableStats['sql_count'])); // minus header line
    }
}

// Missing tables depending on direction
if ($direction === 'from_to') {
    $missingTables = array_values(array_diff($tablesFrom, $tablesTo)); // missing in TO
    $srcDbForMissing = $fromDb;
    $dstDbForMissing = $toDb;
    $pdoSrcForMissing = $pdoFrom;
} else {
    $missingTables = array_values(array_diff($tablesTo, $tablesFrom)); // missing in FROM
    $srcDbForMissing = $toDb;
    $dstDbForMissing = $fromDb;
    $pdoSrcForMissing = $pdoTo;
}

$missingTablesSql = [];
$missingTablesCount = 0;

foreach ($missingTables as $table) {
    $createStmt = $pdoSrcForMissing->query("SHOW CREATE TABLE `{$table}`")->fetch();
    $sql = $createStmt['Create Table'] ?? '';

    if ($sql) {
        // Convert: CREATE TABLE `table`
        // To:      CREATE TABLE `target_db`.`table`
        $sql = preg_replace(
            '/^CREATE TABLE\s+`?(\w+)`?/i',
            "CREATE TABLE `{$dstDbForMissing}`.`$1`",
            $sql
        );

        $sql = "-- Command to create missing table `$table`\n"
             . "-- From database: `{$srcDbForMissing}` → To database: `{$dstDbForMissing}`\n"
             . $sql . ";";

        $missingTablesSql[] = $sql;
        $missingTablesCount++;
    }
}


$directionLabel = $direction === 'from_to'
    ? "$fromDb → $toDb"
    : "$toDb → $fromDb";
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Document Commands - <?= htmlspecialchars($directionLabel) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900">
<div class="max-w-6xl mx-auto p-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold">DB Comparison Documentation</h1>
            <p class="text-xs text-slate-600 mt-1">
                Title: <b><?= $pair['label'] ?></b> |
                Direction: <b><?= htmlspecialchars($directionLabel) ?></b>
            </p>
        </div>

        <div class="flex gap-2 print:hidden">
            <button onclick="window.print()"
                    class="rounded-xl bg-slate-900 text-white px-4 py-2 text-xs font-medium hover:bg-slate-800 shadow-sm">
                Print
            </button>
            <button onclick="document.getElementById('sql-output').scrollIntoView({behavior:'smooth'})"
                    class="rounded-xl bg-slate-700 text-white px-4 py-2 text-xs font-medium hover:bg-slate-600 shadow-sm">
                Go to SQL
            </button>
        </div>
    </div>

    <!-- SUMMARY -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
        <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <div class="text-xs text-slate-500">Matched tables (both DB)</div>
            <div class="text-2xl font-bold"><?= count($commonTables) ?></div>
        </div>

        <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <div class="text-xs text-slate-500">Missing tables in <?= $pair['db_from_name'] ?></div>
            <div class="text-2xl font-bold text-purple-600"><?= $missingTablesCount ?></div>
        </div>

        <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <div class="text-xs text-slate-500">Tables for update in <?= $pair['db_from_name'] ?></div>
            <div class="text-2xl font-bold text-emerald-600"><?= $tablesChanged ?></div>
        </div>

        <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <div class="text-xs text-slate-500">No table changes in <?= $pair['db_from_name'] ?></div>
            <div class="text-2xl font-bold text-slate-500"><?= $tablesNoChanges ?></div>
        </div>

        <!-- <div class="bg-white rounded-2xl p-4 shadow-sm border">
            <div class="text-xs text-slate-500">SQL Commands</div>
            <div class="text-2xl font-bold"><?= $totalSqlCommands - 1 ?></div>
        </div> -->
    </div>

    <!-- TABLE LIST -->
    <div class="mt-6 bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="font-semibold">Table Summary</h2>
            <p class="text-xs text-slate-500">Shows which tables have differences.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-100 text-slate-600">
                <tr>
                    <th class="text-left px-4 py-3">Table</th>
                    <th class="text-center px-4 py-3">Status</th>
                    <th class="text-center px-4 py-3">Columns</th>
                    <th class="text-center px-4 py-3">PK</th>
                    <th class="text-center px-4 py-3">Unique</th>
                    <th class="text-center px-4 py-3">FK</th>
                    <th class="text-center px-4 py-3">SQL Lines</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tableSummaries as $row): ?>
                    <tr class="border-t">
                        <td class="px-4 py-3 font-medium"><?= htmlspecialchars($row['table']) ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($row['changed']): ?>
                                <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-700 px-3 py-1 text-xs font-semibold">
                                    CHANGED
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-600 px-3 py-1 text-xs font-semibold">
                                    NO CHANGES
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center"><?= (int)$row['column_changes'] ?></td>
                        <td class="px-4 py-3 text-center"><?= (int)$row['pk_changes'] ?></td>
                        <td class="px-4 py-3 text-center"><?= (int)$row['unique_changes'] ?></td>
                        <td class="px-4 py-3 text-center"><?= (int)$row['fk_changes'] ?></td>
                        <td class="px-4 py-3 text-center"><?= (int)$row['sql_count'] - 1 ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($missingTablesCount > 0): ?>
        <div class="mt-8 bg-white rounded-2xl shadow-sm border overflow-hidden">
            <div class="p-4 border-b">
                <h2 class="font-semibold">Missing Tables (Will be Created)</h2>
                <p class="text-xs text-slate-500">
                    These tables exist in source but not in destination.
                </p>
            </div>

            <div class="p-4 space-y-2">
                <?php foreach ($missingTables as $t): ?>
                    <div class="rounded-xl border border-slate-200 px-4 py-2 text-xs flex items-center justify-between">
                        <span class="font-medium"><?= htmlspecialchars($t) ?></span>
                        <span class="text-xs px-2 py-1 rounded-full bg-purple-100 text-purple-700 font-semibold">
                            MISSING
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

<?php
$sqlOutputText = trim(implode("\n\n", array_filter(array_merge($missingTablesSql, $allSqlOutput))));
?>

    <!-- SQL OUTPUT -->
    <div id="sql-output" class="mt-8 bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="font-semibold">SQL Commands Output</h2>
            <p class="text-xs text-slate-500">Only tables with changes are included.</p>
        </div>

        <div class="p-4">
            <pre class="text-xs leading-relaxed whitespace-pre-wrap rounded-xl p-4 overflow-auto">
<?= $sqlOutputText !== '' 
? htmlspecialchars($sqlOutputText) 
: "No SQL commands generated. Tables are already synchronized." ?>
            </pre>
        </div>
    </div>

</div>
</body>
</html>
