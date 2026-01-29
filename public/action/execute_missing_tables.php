<?php
require_once __DIR__ . '/../../bootstrap.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

$pairId = $data['pair_id'] ?? null;
$direction = $data['direction'] ?? null;
$sql = $data['sql'] ?? null;

if (!$pairId || !in_array($direction, ['from_to','to_from']) || !$sql) {
    echo json_encode(['success'=>false, 'error'=>'Invalid request']); exit;
}

// Load DB pair from main system DB
try {
    $conn = new PDO("mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4",
                    $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]);
    $stmt = $conn->prepare("SELECT * FROM db_connections WHERE id=:id");
    $stmt->execute([':id'=>$pairId]);
    $pair = $stmt->fetch();
    if(!$pair) throw new Exception("DB pair not found");

    // Choose target DB
    if($direction==='from_to'){
        $pdoTarget = new PDO("mysql:host={$pair['db_to_host']};port={$pair['db_to_port']};dbname={$pair['db_to_name']};charset=utf8mb4",
                             $pair['db_to_user'], $pair['db_to_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        $pdoTarget = new PDO("mysql:host={$pair['db_from_host']};port={$pair['db_from_port']};dbname={$pair['db_from_name']};charset=utf8mb4",
                             $pair['db_from_user'], $pair['db_from_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    $pdoTarget->exec($sql);
    echo json_encode(['success'=>true]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
