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

$selectedId = $_GET['pair_id'] ?? null;
$direction = $_GET['direction'] ?? 'from_to';
$sqlCommands = [];
$error = null;

if ($selectedId) {
    $stmt = $conn->prepare("SELECT * FROM db_connections WHERE id = :id");
    $stmt->execute([':id' => $selectedId]);
    $pair = $stmt->fetch();

    if (!$pair) {
        $error = "Selected DB connection pair not found.";
    } else {
        try {
            $fromDb = $direction === 'from_to' ? $pair['db_from_name'] : $pair['db_to_name'];
            $toDb   = $direction === 'from_to' ? $pair['db_to_name'] : $pair['db_from_name'];

            $dsnTo = "mysql:host={$pair['db_to_host']};port={$pair['db_to_port']};dbname={$toDb};charset=utf8mb4";
            $pdoTo = new PDO($dsnTo, $pair['db_to_user'], $pair['db_to_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Get tables in FROM DB
            $dsnFrom = "mysql:host={$pair['db_from_host']};port={$pair['db_from_port']};dbname={$fromDb};charset=utf8mb4";
            $pdoFrom = new PDO($dsnFrom, $pair['db_from_user'], $pair['db_from_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $tablesFrom = array_column($pdoFrom->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='{$fromDb}'")->fetchAll(), 'TABLE_NAME');
            $tablesTo   = array_column($pdoTo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='{$toDb}'")->fetchAll(), 'TABLE_NAME');

            $missingTables = array_diff($tablesFrom, $tablesTo);

            // Generate SQL for missing tables
            foreach ($missingTables as $table) {
                $createStmt = $pdoFrom->query("SHOW CREATE TABLE `{$table}`")->fetch();
                $sql = $createStmt['Create Table'] ?? '';

                // Prepend comment describing the command
                if ($sql) {
                    $sql = "-- Command to create missing table `$table` in `$toDb`\n" . $sql . ";";
                }

                $sqlCommands[$table] = $sql;
            }

        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generate Missing Tables - DB Compare</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-300">

<!-- Header -->
<div class="bg-white border-b border-slate-200">
    <div class="max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">Generate Missing Tables</h1>
            <p class="text-sm text-slate-700">SQL commands to create missing tables in <?= htmlspecialchars($toDb) ?></p>
        </div>
        <a href="table_comparison.php?pair_id=<?= $selectedId ?>"
           class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 transition">
            ← Back to Table Comparison
        </a>
    </div>
</div>

<div class="max-w-7xl mx-auto px-6 py-8 space-y-6">

    <?php if ($error): ?>
        <div class="rounded-2xl border border-red-200 bg-red-50 p-5 text-red-700">
            <div class="font-semibold">Error</div>
            <div class="text-sm mt-1"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php elseif (empty($sqlCommands)): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-slate-700 shadow-sm">
            No missing tables found.
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 gap-6">
            <?php foreach ($sqlCommands as $table => $sql): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-table text-slate-600"></i>
                            <span class="font-semibold"><?= htmlspecialchars($table) ?></span> <div class="run-tooltip text-green-600 text-xs mt-1 hidden">Executed!</div>
                        </div>
                        <div class="flex gap-2">
                            <button class="copy-btn text-xs text-slate-700 hover:text-slate-900" data-target="sql-<?= htmlspecialchars($table) ?>">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                            <button class="run-btn text-xs bg-green-600 text-white px-2 py-1 rounded hover:bg-green-500"
                                    data-target="sql-<?= htmlspecialchars($table) ?>" data-table="<?= htmlspecialchars($table) ?>">
                                Run
                            </button>
                        </div>
                    </div>
                    <pre id="sql-<?= htmlspecialchars($table) ?>" class="p-4 overflow-auto text-xs font-mono bg-slate-50"><?= htmlspecialchars($sql) ?></pre>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Toast container -->
<div id="toast-container" class="fixed top-5 right-5 space-y-2 z-50"></div>

<script>
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

    // Run SQL functionality
    document.querySelectorAll('.run-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.dataset.target;
            const sqlText = document.getElementById(targetId).innerText;
            const tableName = btn.dataset.table;

            if (!confirm("Are you sure you want to run this SQL? This will create the table in the target DB.")) return;

            fetch('action/execute_missing_tables.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    pair_id: <?= json_encode($selectedId) ?>,
                    direction: '<?= $direction ?>',
                    table: tableName,
                    sql: sqlText
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast('Table created successfully!', 'success');
                    const tooltip = btn.closest('div.bg-white').querySelector('.run-tooltip');
                    tooltip.classList.remove('hidden');
                    setTimeout(() => tooltip.classList.add('hidden'), 2000);
                    setTimeout(() => location.reload(), 1000); // refresh table listing
                } else {
                    showToast('Error: ' + data.error, 'error', 5000);
                }
            })
            .catch(err => showToast('Request failed: ' + err, 'error', 5000));
        });
    });

    // Toast helper
    function showToast(message, type='success', duration=2000) {
        const toast = document.createElement('div');
        toast.className = `px-4 py-2 rounded shadow text-white ${type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-blue-600'}`;
        toast.innerText = message;
        document.getElementById('toast-container').appendChild(toast);
        setTimeout(() => toast.remove(), duration);
    }
</script>

</body>
</html>
