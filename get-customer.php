<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/firestore-helper.php';

$data       = json_decode(file_get_contents('php://input'), true);
$customerId = trim($data['customerId'] ?? '');

if (!$customerId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing customerId']);
    exit;
}

$results = queryWhere('customers', 'customerId', '=', $customerId, 1);
if (empty($results)) {
    http_response_code(404);
    echo json_encode(['error' => 'Customer not found']);
    exit;
}

echo json_encode(['success' => true, 'data' => $results[0]]);
?>
