<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/firestore-helper.php';

$data   = json_decode(file_get_contents('php://input'), true);
$userId = trim($data['userId'] ?? '');

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing userId']);
    exit;
}

$doc = getDoc('users', $userId);
if ($doc === null) {
    // User doc missing — create with ₹100 free welcome balance
    $walletBalance = 100.0;
    addDocWithId('users', $userId, [
        'walletBalance'         => $walletBalance,
        'credits'               => 0,
        'totalFormsFilledCount' => 0,
    ]);
    echo json_encode(['walletBalance' => $walletBalance, 'totalFormsFilledCount' => 0, 'name' => '', 'found' => false]);
    exit;
}

// Auto-migrate: if walletBalance missing or zero for existing user, convert old credits → ₹
if (!isset($doc['walletBalance']) || (float)$doc['walletBalance'] === 0.0) {
    $oldCredits    = (int)($doc['credits'] ?? 0);
    $walletBalance = $oldCredits > 0 ? round($oldCredits * 4, 2) : 100.0;
    updateDoc('users', $userId, ['walletBalance' => $walletBalance]);
    $doc['walletBalance'] = $walletBalance;
}

echo json_encode([
    'walletBalance'         => (float)($doc['walletBalance']),
    'totalFormsFilledCount' => (int)($doc['totalFormsFilledCount'] ?? 0),
    'name'                  => $doc['name'] ?? '',
    'found'                 => true
]);
?>
