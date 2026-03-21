<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/firestore-helper.php';

const COST_PER_FIELD_PAISE = 2;    // 2 paise per field
const COST_PER_FIELD_RS    = 0.02; // ₹0.02 per field (2 paise)
const MIN_BALANCE          = 0.02; // minimum ₹0.02 to allow fill

$data       = json_decode(file_get_contents('php://input'), true);
$userId     = $data['userId']       ?? null;
$filled     = max(1, (int)($data['fieldsFilledCount'] ?? 1));
$customerId = $data['customerId']   ?? null;
$pageTitle  = trim($data['pageTitle']  ?? '');
$pageUrl    = trim($data['pageUrl']    ?? '');
$portalName = trim($data['portalName'] ?? '');
// Derive portal name from URL if not provided
if (!$portalName && $pageUrl) {
    $host = parse_url($pageUrl, PHP_URL_HOST) ?: $pageUrl;
    $portalName = preg_replace('/^www\./', '', $host);
}

if (!$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing userId']);
    exit;
}

$doc = getDoc('users', $userId);
if ($doc === null) {
    // User doc missing — auto-create with ₹100 (same logic as get-credits.php)
    addDocWithId('users', $userId, [
        'walletBalance'         => 100.0,
        'credits'               => 0,
        'totalFormsFilledCount' => 0,
    ]);
    $doc = ['walletBalance' => 100.0, 'totalFormsFilledCount' => 0];
}

$walletBalance = (float)($doc['walletBalance'] ?? 0);
$cost          = round($filled * COST_PER_FIELD_RS, 2); // e.g. 50 fields × ₹0.02 = ₹1.00

if ($walletBalance < MIN_BALANCE) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient wallet balance', 'walletBalance' => $walletBalance]);
    exit;
}

// Deduct what we can (cap at available balance)
$deducted   = min($cost, $walletBalance);
$newBalance = round($walletBalance - $deducted, 2);
$newCount   = (int)($doc['totalFormsFilledCount'] ?? 0) + 1;

updateDoc('users', $userId, [
    'walletBalance'         => $newBalance,
    'totalFormsFilledCount' => $newCount
]);

addDoc('transactions', [
    'userId'      => $userId,
    'type'        => 'deduction',
    'amount'      => $deducted,
    'description' => "Form filled ($filled fields × 2 paise) — ₹" . number_format($deducted, 2) . " deducted",
]);

// Log the form fill per customer (for Form History tab)
$logWritten = false;
if ($customerId) {
    $logWritten = addDoc('formLogs', [
        'userId'      => $userId,
        'customerId'  => $customerId,
        'pageTitle'   => $pageTitle ?: 'Unknown Form',
        'pageUrl'     => $pageUrl,
        'portalName'  => $portalName,
        'fieldsCount' => $filled,
        'cost'        => $deducted,
    ]);
}

echo json_encode([
    'success'       => true,
    'walletBalance' => $newBalance,
    'deducted'      => $deducted,
    'fieldsCount'   => $filled,
    'ratePerField'  => '2 paise (₹0.02)',
    'logWritten'    => $logWritten,
]);
?>
