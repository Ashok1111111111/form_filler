<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/firestore-helper.php';

const COST_PER_FIELD_PAISE = 20;   // 20 paise per field
const COST_PER_FIELD_RS    = 0.20; // ₹0.20 per field (20 paise)
const MIN_BALANCE          = 0.20; // minimum ₹0.20 to allow fill

function getBusinessDayKey(): string {
    $tz = new DateTimeZone('Asia/Kolkata');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

function numericOrNull(mixed $value): ?float {
    if (is_int($value) || is_float($value)) return (float)$value;
    if (is_string($value) && is_numeric($value)) return (float)$value;
    return null;
}

function summarizeAiMeta(mixed $meta): array {
    $meta = is_array($meta) ? $meta : [];
    $present = !empty($meta);
    return [
        'present' => $present,
        'costKnown' => $present ? (bool)($meta['costKnown'] ?? false) : true,
        'costRs' => $present ? numericOrNull($meta['costRs'] ?? null) : 0.0,
        'costUsd' => $present ? numericOrNull($meta['costUsd'] ?? null) : 0.0,
        'promptTokens' => (int)($meta['promptTokens'] ?? 0),
        'completionTokens' => (int)($meta['completionTokens'] ?? 0),
        'totalTokens' => (int)($meta['totalTokens'] ?? 0),
        'callCount' => (int)($meta['callCount'] ?? 0),
        'models' => is_array($meta['models'] ?? null) ? $meta['models'] : [],
        'raw' => $present ? $meta : null,
    ];
}

$data       = json_decode(file_get_contents('php://input'), true);
$userId     = $data['userId']       ?? null;
$filled     = max(1, (int)($data['fieldsFilledCount'] ?? 1));
$customerId = $data['customerId']   ?? null;
$fillMode   = trim($data['fillMode'] ?? 'saved_customer');
$sessionSource = trim($data['sessionSource'] ?? '');
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
        'dailyStatsDate'        => getBusinessDayKey(),
        'dailyFormsFilledCount' => 0,
        'dailyAmountSpent'      => 0.0,
        'totalFormsFilledCount' => 0,
    ]);
    $doc = [
        'walletBalance' => 100.0,
        'credits' => 0,
        'dailyStatsDate' => getBusinessDayKey(),
        'dailyFormsFilledCount' => 0,
        'dailyAmountSpent' => 0.0,
        'totalFormsFilledCount' => 0,
    ];
}

// Keep deduction behavior aligned with get-credits.php / dashboard stats.
if (!isset($doc['walletBalance']) || (float)($doc['walletBalance'] ?? 0) === 0.0) {
    $oldCredits = (int)($doc['credits'] ?? 0);
    $migratedWallet = $oldCredits > 0 ? round($oldCredits * 4, 2) : 100.0;
    updateDoc('users', $userId, ['walletBalance' => $migratedWallet]);
    $doc['walletBalance'] = $migratedWallet;
}

$walletBalance = (float)($doc['walletBalance'] ?? 0);
$cost          = round($filled * COST_PER_FIELD_RS, 2); // e.g. 50 fields × ₹0.20 = ₹10.00
$todayKey      = getBusinessDayKey();
$storedDayKey  = (string)($doc['dailyStatsDate'] ?? '');
$todayForms    = $storedDayKey === $todayKey ? (int)($doc['dailyFormsFilledCount'] ?? 0) : 0;
$todayAmount   = $storedDayKey === $todayKey ? (float)($doc['dailyAmountSpent'] ?? 0) : 0.0;

$agentAi = summarizeAiMeta($data['agentAi'] ?? null);
$scanAi = summarizeAiMeta($data['scanAi'] ?? null);
$requiresScanAi = ($fillMode === 'session_scan' || $sessionSource === 'dashboard_scan');
$scanAiMissing = $requiresScanAi && !$scanAi['present'];
$aiCostKnown = !$scanAiMissing && $agentAi['costKnown'] && $scanAi['costKnown'];
$aiCostRs = $aiCostKnown
    ? round((float)($agentAi['costRs'] ?? 0) + (float)($scanAi['costRs'] ?? 0), 4)
    : null;
$aiCostUsd = $aiCostKnown
    ? round((float)($agentAi['costUsd'] ?? 0) + (float)($scanAi['costUsd'] ?? 0), 6)
    : null;

if ($walletBalance < MIN_BALANCE) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient wallet balance', 'walletBalance' => $walletBalance]);
    exit;
}

// Deduct what we can (cap at available balance)
$deducted   = min($cost, $walletBalance);
$newBalance = round($walletBalance - $deducted, 2);
$newCount   = (int)($doc['totalFormsFilledCount'] ?? 0) + 1;
$newTodayForms = $todayForms + 1;
$newTodayAmount = round($todayAmount + $deducted, 2);
$grossMarginRs = $aiCostKnown ? round($deducted - $aiCostRs, 4) : null;
$revenuePerFieldRs = round($deducted / max(1, $filled), 4);
$aiCostPerFieldRs = $aiCostKnown ? round($aiCostRs / max(1, $filled), 4) : null;
$grossMarginPerFieldRs = $aiCostKnown ? round($grossMarginRs / max(1, $filled), 4) : null;
$txDescription = "Form filled ($filled fields × 20 paise) — ₹" . number_format($deducted, 2) . " deducted";
if ($aiCostKnown) {
    $txDescription .= " | AI ₹" . number_format($aiCostRs, 2) . " | Margin ₹" . number_format($grossMarginRs, 2);
}

$userUpdateResult = updateDocDetailed('users', $userId, [
    'walletBalance'         => $newBalance,
    'totalFormsFilledCount' => $newCount,
    'dailyStatsDate'        => $todayKey,
    'dailyFormsFilledCount' => $newTodayForms,
    'dailyAmountSpent'      => $newTodayAmount,
]);
$userUpdated = $userUpdateResult['success'] ?? false;

if (!$userUpdated) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update wallet/user stats',
        'walletBalance' => $walletBalance,
        'diagnostic' => [
            'step' => 'update_user',
            'http_code' => $userUpdateResult['code'] ?? null,
            'curl_error' => $userUpdateResult['curl_error'] ?? '',
            'token_present' => $userUpdateResult['token_present'] ?? false,
            'body' => $userUpdateResult['body'] ?? [],
            'raw' => $userUpdateResult['raw'] ?? '',
        ],
    ]);
    exit;
}

$txResult = addDocDetailed('transactions', [
    'userId'      => $userId,
    'type'        => 'deduction',
    'amount'      => $deducted,
    'fieldsCount' => $filled,
    'revenueRs'   => $deducted,
    'fillMode'    => $fillMode,
    'sessionSource' => $sessionSource,
    'pageTitle'   => $pageTitle ?: 'Unknown Form',
    'pageUrl'     => $pageUrl,
    'portalName'  => $portalName,
    'aiCostKnown' => $aiCostKnown,
    'aiCostRs'    => $aiCostRs,
    'aiCostUsd'   => $aiCostUsd,
    'agentAiCostRs' => $agentAi['present'] ? $agentAi['costRs'] : null,
    'scanAiCostRs'  => $scanAi['present'] ? $scanAi['costRs'] : null,
    'grossMarginRs' => $grossMarginRs,
    'revenuePerFieldRs' => $revenuePerFieldRs,
    'aiCostPerFieldRs' => $aiCostPerFieldRs,
    'grossMarginPerFieldRs' => $grossMarginPerFieldRs,
    'promptTokens' => $agentAi['promptTokens'] + $scanAi['promptTokens'],
    'completionTokens' => $agentAi['completionTokens'] + $scanAi['completionTokens'],
    'totalTokens' => $agentAi['totalTokens'] + $scanAi['totalTokens'],
    'agentAi'     => $agentAi['raw'],
    'scanAi'      => $scanAi['raw'],
    'description' => $txDescription,
]);
$txWritten = $txResult['success'] ?? false;

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
        'revenueRs'   => $deducted,
        'aiCostKnown' => $aiCostKnown,
        'aiCostRs'    => $aiCostRs,
        'agentAiCostRs' => $agentAi['present'] ? $agentAi['costRs'] : null,
        'scanAiCostRs'  => $scanAi['present'] ? $scanAi['costRs'] : null,
        'grossMarginRs' => $grossMarginRs,
        'revenuePerFieldRs' => $revenuePerFieldRs,
        'aiCostPerFieldRs' => $aiCostPerFieldRs,
        'grossMarginPerFieldRs' => $grossMarginPerFieldRs,
        'promptTokens' => $agentAi['promptTokens'] + $scanAi['promptTokens'],
        'completionTokens' => $agentAi['completionTokens'] + $scanAi['completionTokens'],
        'totalTokens' => $agentAi['totalTokens'] + $scanAi['totalTokens'],
        'agentAi'     => $agentAi['raw'],
        'scanAi'      => $scanAi['raw'],
    ]);
}

echo json_encode([
    'success'       => true,
    'walletBalance' => $newBalance,
    'deducted'      => $deducted,
    'fieldsCount'   => $filled,
    'ratePerField'  => '20 paise (₹0.20)',
    'aiCostKnown'   => $aiCostKnown,
    'aiCostRs'      => $aiCostRs,
    'aiCostUsd'     => $aiCostUsd,
    'grossMarginRs' => $grossMarginRs,
    'revenuePerFieldRs' => $revenuePerFieldRs,
    'aiCostPerFieldRs' => $aiCostPerFieldRs,
    'grossMarginPerFieldRs' => $grossMarginPerFieldRs,
    'logWritten'    => $logWritten,
    'transactionWritten' => $txWritten,
    'userUpdated'   => $userUpdated,
    'warning'       => $txWritten ? null : 'Transaction log write failed, but wallet and dashboard stats were updated.',
    'transactionDiagnostic' => $txWritten ? null : [
        'step' => 'write_transaction',
        'http_code' => $txResult['code'] ?? null,
        'curl_error' => $txResult['curl_error'] ?? '',
        'token_present' => $txResult['token_present'] ?? false,
        'body' => $txResult['body'] ?? [],
        'raw' => $txResult['raw'] ?? '',
    ],
    'dailyStatsDate' => $todayKey,
    'dailyFormsFilledCount' => $newTodayForms,
    'dailyAmountSpent' => $newTodayAmount,
]);
?>
