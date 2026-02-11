<?php

require_once __DIR__ . '/../bootstrap.php';

$data = DashboardController::handle();
extract($data);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard - DB Compare</title>

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-300 text-slate-800">

    <!-- Toast container -->
    <div id="toast-container" class="fixed top-5 right-5 space-y-2 z-50"></div>


    <!-- Top Header -->
    <div class="bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-6 py-5 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">DB Compare System</h1>
                <p class="text-sm text-slate-500">Dashboard • Manage database connections</p>
            </div>

            <a href="table_comparison.php"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 transition">
                Go to Table Comparison →
            </a>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-6 py-8 space-y-8">

        <!-- Add Connection Form -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <form method="POST" id="add-connection-form" class="p-6 space-y-6">
                <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold">Add Database Connection</h2>
                        <p class="text-sm text-slate-500">
                            Save a pair of databases you want to compare.
                        </p>
                    </div>

                    <!-- Submit button moved here -->
                    <button type="submit" name="add_connection"
                        form="add-connection-form"
                        class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800 transition shadow-sm">
                        + Add Connection
                    </button>
                </div>

                <!-- Label -->
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Label
                    </label>
                    <input type="text" name="label" placeholder="e.g. Prod vs Staging" required
                        class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                </div>

                <!-- Two Columns -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- DB FROM -->
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-semibold text-slate-800 mb-4">Database From</h3>

                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Host</label>
                                <input type="text" name="db_from_host" value="<?=$dbHost?>" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Port</label>
                                <input type="text" name="db_from_port" value="<?=$dbPort?>" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Database Name</label>
                                <input type="text" name="db_from_name" placeholder="db_name_from" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Username</label>
                                <input type="text" name="db_from_user" placeholder="root" value="<?=$dbUsername?>" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Password</label>
                                <input type="password" name="db_from_pass" placeholder="••••••••" value="<?=$dbPassword?>"
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>
                        </div>
                    </div>

                    <!-- DB TO -->
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                        <h3 class="font-semibold text-slate-800 mb-4">Database To</h3>

                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Host</label>
                                <input type="text" name="db_to_host" value="<?=$dbHost?>" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Port</label>
                                <input type="text" name="db_to_port" value="<?=$dbPort?>" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Database Name</label>
                                <input type="text" name="db_to_name" placeholder="db_name_to" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Username</label>
                                <input type="text" name="db_to_user" placeholder="root" value="<?=$dbUsername?>" required
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">Password</label>
                                <input type="password" name="db_to_pass" placeholder="••••••••" value="<?=$dbPassword?>"
                                    class="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900 focus:border-slate-900" />
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Saved Connections Table -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-semibold">Saved Connections</h2>
                    <p class="text-sm text-slate-500">
                        These are your stored DB pairs.
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-slate-700">
                        <tr>
                            <th class="text-left px-6 py-3 font-semibold">ID</th>
                            <th class="text-left px-6 py-3 font-semibold">Label</th>
                            <th class="text-left px-6 py-3 font-semibold">DB From</th>
                            <th class="text-left px-6 py-3 font-semibold">DB To</th>
                            <th class="text-left px-6 py-3 font-semibold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php if (count($dbPairs) === 0): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-6 text-center text-slate-500">
                                    No saved connections yet. Add one above.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($dbPairs as $row): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-6 py-4"><?= $row['id'] ?></td>
                                    <td class="px-6 py-4 font-medium">
                                        <?= htmlspecialchars($row['label'] ?? '-') ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-slate-800 font-medium"><?= htmlspecialchars($row['db_from_name']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($row['db_from_host']) ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-slate-800 font-medium"><?= htmlspecialchars($row['db_to_name']) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($row['db_to_host']) ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <a href="index.php?delete_id=<?= $row['id'] ?>"
                                        onclick="return confirm('Delete this connection?')"
                                        class="inline-flex items-center rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-100 transition">
                                            Delete
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

<script>
    function showToast(message, type='success', duration=2000) {
        const toast = document.createElement('div');
        toast.className = `px-4 py-2 rounded shadow text-white 
            ${type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-blue-600'}`;
        toast.innerText = message;

        const container = document.getElementById('toast-container');
        container.appendChild(toast);

        setTimeout(() => toast.remove(), duration);
    }

    // Show toast if PHP passed a message
    <?php if ($toastMessage): ?>
        showToast(<?= json_encode($toastMessage) ?>, <?= json_encode($toastType) ?>, 3000);
    <?php endif; ?>
</script>


</body>
</html>
