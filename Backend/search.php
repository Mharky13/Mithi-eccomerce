<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

header('Content-Type: application/json');

try {
    $pdo = Database::connect();

    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if ($query !== '') {
        $stmt = $pdo->prepare('SELECT * FROM product WHERE name LIKE ? OR description LIKE ?');
        $searchTerm = '%' . $query . '%';
        $stmt->execute([$searchTerm, $searchTerm]);
    } else {
        $stmt = $pdo->query('SELECT * FROM assets LIMIT 10');
    }
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $products
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Query failed: ' . $e->getMessage()
    ]);
}