<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/firestore-helper.php';

$data = json_decode(file_get_contents('php://input'), true);
$userId = trim($data['userId'] ?? '');

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing userId']);
    exit;
}

function getDashboardBusinessDayKey(): string {
    $tz = new DateTimeZone('Asia/Kolkata');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

$todayKey = getDashboardBusinessDayKey();
$userDoc = getDoc('users', $userId);

if ($userDoc === null) {
    $userDoc = [
        'walletBalance' => 100.0,
        'credits' => 0,
        'totalFormsFilledCount' => 0,
        'dailyStatsDate' => $todayKey,
        'dailyFormsFilledCount' => 0,
        'dailyAmountSpent' => 0.0,
    ];
    addDocWithId('users', $userId, $userDoc);
}

// Keep wallet migration behavior aligned with get-credits.php so the
// dashboard stats refresh never overwrites a valid wallet display with 0.
if (!isset($userDoc['walletBalance']) || (float)$userDoc['walletBalance'] === 0.0) {
    $oldCredits = (int)($userDoc['credits'] ?? 0);
    $walletBalance = $oldCredits > 0 ? round($oldCredits * 4, 2) : 100.0;
    updateDoc('users', $userId, ['walletBalance' => $walletBalance]);
    $userDoc['walletBalance'] = $walletBalance;
}

$walletBalance = (float)($userDoc['walletBalance'] ?? 0);
$totalFormsFilledCount = (int)($userDoc['totalFormsFilledCount'] ?? 0);

$formsToday = ((string)($userDoc['dailyStatsDate'] ?? '') === $todayKey)
    ? (int)($userDoc['dailyFormsFilledCount'] ?? 0)
    : 0;
$amountSpentToday = ((string)($userDoc['dailyStatsDate'] ?? '') === $todayKey)
    ? (float)($userDoc['dailyAmountSpent'] ?? 0)
    : 0.0;

echo json_encode([
    'success' => true,
    'walletBalance' => $walletBalance,
    'totalFormsFilledCount' => $totalFormsFilledCount,
    'formsToday' => $formsToday,
    'amountSpentToday' => round($amountSpentToday, 2),
    'dailyStatsDate' => $todayKey,
]);
?>
