<?php
require_once __DIR__ . '/../bootstrap.php';

// MAIN SYSTEM DB (where db_connections is stored)
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

// Fetch saved DB connection pairs
$stmt = $conn->query("SELECT * FROM db_connections ORDER BY id DESC");
$dbPairs = $stmt->fetchAll();

// Selected pair
$selectedId = $_GET['pair_id'] ?? null;

$tablesFrom = [];
$tablesTo = [];

$commonTables = [];
$missingInFrom = [];
$missingInTo = [];

$error = null;

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

if ($selectedId) {
    // Load selected pair
    $stmt = $conn->prepare("SELECT * FROM db_connections WHERE id = :id");
    $stmt->execute([':id' => $selectedId]);
    $pair = $stmt->fetch();

    if (!$pair) {
        $error = "Selected DB connection pair not found.";
    } else {
        try {
            // Connect to DB FROM
            $dsnFrom = "mysql:host={$pair['db_from_host']};port={$pair['db_from_port']};dbname={$pair['db_from_name']};charset=utf8mb4";
            $pdoFrom = new PDO($dsnFrom, $pair['db_from_user'], $pair['db_from_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Connect to DB TO
            $dsnTo = "mysql:host={$pair['db_to_host']};port={$pair['db_to_port']};dbname={$pair['db_to_name']};charset=utf8mb4";
            $pdoTo = new PDO($dsnTo, $pair['db_to_user'], $pair['db_to_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Fetch tables
            $tablesFrom = getTables($pdoFrom, $pair['db_from_name']);
            $tablesTo   = getTables($pdoTo, $pair['db_to_name']);

            // Compare
            $commonTables = array_values(array_intersect($tablesFrom, $tablesTo));
            $missingInTo  = array_values(array_diff($tablesFrom, $tablesTo));
            $missingInFrom = array_values(array_diff($tablesTo, $tablesFrom));

        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Table Comparison - DB Compare</title>

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Font Awesome -->
    <link
      rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
      crossorigin="anonymous"
      referrerpolicy="no-referrer"
    />
</head>
<body class="bg-gray-300">

    <!-- Header -->
    <div class="bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Table Comparison</h1>
                <p class="text-sm text-slate-700">Compare tables between two databases</p>
            </div>

            <a href="index.php"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 transition">
                ← Back to Dashboard
            </a>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-6 py-8 space-y-6">

        <!-- Selector -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
            <form method="GET" class="flex flex-col lg:flex-row gap-4 lg:items-end">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Select Connection Pair
                    </label>

                    <select name="pair_id"
                        class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900"
                        required>
                        <option value="">-- Select --</option>
                        <?php foreach ($dbPairs as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($selectedId == $p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['label'] ?? 'No Label') ?>
                                (<?= htmlspecialchars($p['db_from_name']) ?> → <?= htmlspecialchars($p['db_to_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit"
                    class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800 transition shadow-sm">
                    Compare Tables
                </button>
            </form>
        </div>

        <!-- Error -->
        <?php if ($error): ?>
            <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-700">
                <div class="font-semibold">Error</div>
                <div class="text-sm mt-1"><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($selectedId && !$error): ?>

        <!-- Documentation Buttons -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <div class="text-sm text-slate-700 font-medium">Documentation / Report</div>
                    <div class="text-xs text-slate-500">
                        Generate full table + structure comparison report
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a target="_blank"
                    href="document_commands.php?pair_id=<?= $selectedId ?>&direction=to_from"
                    class="rounded-xl bg-purple-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-purple-500 transition shadow-sm">
                        🧾 Document (<?= $pair['db_to_name'].' → '.$pair['db_from_name']?>)
                    </a>

                    <a target="_blank"
                    href="document_commands.php?pair_id=<?= $selectedId ?>&direction=from_to"
                    class="rounded-xl bg-green-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-green-500 transition shadow-sm">
                        🧾 Document (<?= $pair['db_from_name'].' → '.$pair['db_to_name']?>)
                    </a>
                </div>
            </div>
        </div>


            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <!-- Tables in Both -->
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                    <div class="text-sm text-slate-700">Tables in Both</div>
                    <div class="text-2xl font-semibold mt-1"><?= count($commonTables) ?></div>
                </div>

                <!-- Missing in FROM -->
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="text-sm text-slate-700">
                            Missing Tables in
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium">
                                <?= htmlspecialchars($pair['db_from_name']) ?>
                            </span>
                        </div>
                        <div class="text-2xl font-semibold mt-1"><?= count($missingInFrom) ?></div>
                    </div>

                    <?php if (count($missingInFrom) > 0): ?>
                        <a href="create_missing_tables.php?pair_id=<?= $selectedId ?>&direction=to_from"
                        class="mt-4 inline-block rounded-xl bg-purple-600 px-3 py-1 text-xs font-medium text-white hover:bg-purple-500 transition">
                            Generate SQL for missing tables in <?= htmlspecialchars($pair['db_from_name']) ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Missing in TO -->
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-col justify-between">
                    <div>
                        <div class="text-sm text-slate-700">
                            Missing Tables in
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium">
                                <?= htmlspecialchars($pair['db_to_name']) ?>
                            </span>
                        </div>
                        <div class="text-2xl font-semibold mt-1"><?= count($missingInTo) ?></div>
                    </div>

                    <?php if (count($missingInTo) > 0): ?>
                        <a href="create_missing_tables.php?pair_id=<?= $selectedId ?>&direction=from_to"
                        class="mt-4 inline-block rounded-xl bg-green-600 px-3 py-1 text-xs font-medium text-white hover:bg-green-500 transition">
                            Generate SQL for missing tables in <?= htmlspecialchars($pair['db_to_name']) ?>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Results -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Common -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <div class="text-xl font-semibold text-slate-900 flex items-center gap-1">
                            <i class="fas fa-check-circle text-green-500"></i>
                            Tables in Both
                        </div>
                        <p class="text-sm text-slate-700">Same table name exists in both DBs</p>
                    </div>
                    <div class="p-4 space-y-2 max-h-[420px] overflow-auto">
                        <?php if (count($commonTables) === 0): ?>
                            <div class="text-sm text-slate-700">No common tables found.</div>
                        <?php else: ?>
                        <form method="GET" action="structure_batch_comparison.php" target="_blank">
                            <input type="hidden" name="pair_id" value="<?= htmlspecialchars($selectedId) ?>">
                            <!-- Tables List -->
                            <div class="space-y-2">
                                <?php if (count($commonTables) === 0): ?>
                                    <div class="text-sm text-slate-700">No common tables found.</div>
                                <?php else: ?>

                                    <?php foreach ($commonTables as $t): ?>
                                        <label class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-2 text-sm cursor-pointer hover:bg-slate-50">
                                            <div class="flex items-center gap-2">
                                                <!-- AUTO CHECKED -->
                                                <input type="checkbox" name="tables[]" value="<?= htmlspecialchars($t) ?>"
                                                    checked
                                                    class="h-4 w-4 rounded border-slate-300 hidden">

                                                <span class="font-medium">
                                                    <i class="fas fa-table text-slate-600 mr-1"></i>
                                                    <?= htmlspecialchars($t) ?>
                                                </span>
                                            </div>

                                            <!-- Single Compare -->
                                            <a href="structure_comparison.php?pair_id=<?= $selectedId ?>&table=<?= urlencode($t) ?>"
                                                class="text-slate-700 hover:text-slate-900 text-xs flex items-center gap-1"
                                                onclick="event.stopPropagation();">
                                                <i class="fas fa-code-branch"></i>
                                                <span class="underline">Compare</span>
                                            </a>
                                        </label>
                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </div>

                            <!-- Batch Action Bar (Sticky) -->
                        <div class="flex items-center justify-end gap-2 mt-2">
                            <button type="submit"
                                class="text-slate-700 hover:text-slate-900 text-xs flex items-center gap-1">
                                <i class="fas fa-code-branch"></i>
                                <span class="underline">Compare All Tables</span>
                            </button>
                        </div>


                        </form>


                        <script>
                        document.getElementById('select-all-btn')?.addEventListener('click', () => {
                            const checkboxes = document.querySelectorAll('input[name="tables[]"]');
                            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                            checkboxes.forEach(cb => cb.checked = !allChecked);
                        });
                        </script>

                        <?php endif; ?>
                    </div>
                </div>

                <!-- Missing in FROM -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <div class="text-xl font-semibold text-slate-900 flex items-center gap-1">
                            <i class="fas fa-exclamation-triangle text-yellow-500"></i>
                            Missing Tables in <?= htmlspecialchars($pair['db_from_name']) ?>

                        </div>
                        <p class="text-sm text-slate-700">
                            Exists in 
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium rounded mx-1">
                                <?= htmlspecialchars($pair['db_to_name']) ?>
                            </span>
                            but not in 
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium rounded mx-1">
                                <?= htmlspecialchars($pair['db_from_name']) ?>
                            </span>
                        </p>
                    </div>
                    <div class="p-4 space-y-2 max-h-[420px] overflow-auto">
                        <?php if (count($missingInFrom) === 0): ?>
                            <div class="text-sm text-slate-700">None</div>
                        <?php else: ?>
                            <?php foreach ($missingInFrom as $t): ?>
                                <div class="rounded-xl border border-slate-200 px-4 py-2 text-sm">
                                    <?= htmlspecialchars($t) ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Missing in TO -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <div class="text-xl font-semibold text-slate-900 flex items-center gap-1">
                            <i class="fas fa-exclamation-triangle text-red-500"></i>
                            Missing Tables in <?= htmlspecialchars($pair['db_to_name']) ?>
                        </div>
                        <p class="text-sm text-slate-700">Exists in 
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium rounded mx-1">
                                <?= htmlspecialchars($pair['db_from_name']) ?>
                            </span> 
                            but not in 
                            <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 text-xs font-medium rounded mx-1">
                                <?= htmlspecialchars($pair['db_to_name']) ?>
                            </span>
                        </p>
                    </div>
                    <div class="p-4 space-y-2 max-h-[420px] overflow-auto">
                        <?php if (count($missingInTo) === 0): ?>
                            <div class="text-sm text-slate-700">None</div>
                        <?php else: ?>
                            <?php foreach ($missingInTo as $t): ?>
                                <div class="rounded-xl border border-slate-200 px-4 py-2 text-sm">
                                    <?= htmlspecialchars($t) ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </div>
</body>
</html>
