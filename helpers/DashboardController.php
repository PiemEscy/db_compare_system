<?php

class DashboardController
{
    public static function handle()
    {
        $conn = DatabaseHelper::connectSystem();
        
        // Handle Actions
        self::handleAddConnection($conn);
        self::handleDeleteConnection($conn);

        // Prepare View Data
        return [
            'dbPairs' => self::getDbPairs($conn),
            'toastMessage' => self::getToastMessage(),
            'toastType' => (isset($_GET['success']) && $_GET['success'] === 'error') ? 'error' : 'success', 
            
            // Env defaults
            'dbHost' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'dbPort' => $_ENV['DB_PORT'] ?? '3306',
            'dbDatabase' => $_ENV['DB_DATABASE'] ?? '',
            'dbUsername' => $_ENV['DB_USERNAME'] ?? 'root',
            'dbPassword' => $_ENV['DB_PASSWORD'] ?? '',
        ];
    }

    private static function handleAddConnection($conn)
    {
        if (!isset($_POST['add_connection'])) return;

        $sql = "INSERT INTO db_connections
            (label,
            db_from_host, db_from_port, db_from_name, db_from_user, db_from_pass,
            db_to_host, db_to_port, db_to_name, db_to_user, db_to_pass)
            VALUES
            (:label,
            :db_from_host, :db_from_port, :db_from_name, :db_from_user, :db_from_pass,
            :db_to_host, :db_to_port, :db_to_name, :db_to_user, :db_to_pass)";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ':label' => $_POST['label'] ?? null,
            ':db_from_host' => $_POST['db_from_host'],
            ':db_from_port' => $_POST['db_from_port'] ?? 3306,
            ':db_from_name' => $_POST['db_from_name'],
            ':db_from_user' => $_POST['db_from_user'],
            ':db_from_pass' => $_POST['db_from_pass'] ?? null,
            ':db_to_host' => $_POST['db_to_host'],
            ':db_to_port' => $_POST['db_to_port'] ?? 3306,
            ':db_to_name' => $_POST['db_to_name'],
            ':db_to_user' => $_POST['db_to_user'],
            ':db_to_pass' => $_POST['db_to_pass'] ?? null,
        ]);

        header("Location: index.php?success=added");
        exit;
    }

    private static function handleDeleteConnection($conn)
    {
        if (!isset($_GET['delete_id'])) return;

        $stmt = $conn->prepare("DELETE FROM db_connections WHERE id = ?");
        $stmt->execute([(int) $_GET['delete_id']]);

        header("Location: index.php?success=deleted");
        exit;
    }

    private static function getToastMessage()
    {
        if (!isset($_GET['success'])) return null;

        switch ($_GET['success']) {
            case 'added': return "Database connection added successfully!";
            case 'deleted': return "Database connection deleted successfully!";
            case 'error': return $_GET['msg'] ?? "An error occurred!";
            default: return null;
        }
    }

    private static function getDbPairs($conn)
    {
        return $conn->query("SELECT * FROM db_connections ORDER BY id DESC")->fetchAll();
    }
}
