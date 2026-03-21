<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once __DIR__ . '/firestore-helper.php';

$data       = json_decode(file_get_contents('php://input'), true);
$customerId = $data['customerId'] ?? null;
$userId     = $data['userId']     ?? null;
$limit      = min((int)($data['limit'] ?? 50), 100);

if (!$customerId && !$userId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing customerId or userId']);
    exit;
}

// Build query
$filterField = $customerId ? 'customerId' : 'userId';
$filterValue = $customerId ?: $userId;

$query = [
    'structuredQuery' => [
        'from'    => [['collectionId' => 'formLogs']],
        'where'   => [
            'fieldFilter' => [
                'field' => ['fieldPath' => $filterField],
                'op'    => 'EQUAL',
                'value' => ['stringValue' => $filterValue],
            ]
        ],
        'orderBy' => [['field' => ['fieldPath' => 'timestamp'], 'direction' => 'DESCENDING']],
        'limit'   => $limit,
    ]
];

$res = fsRequest('POST', FIRESTORE_BASE . ':runQuery', $query);

if ($res['code'] !== 200) {
    echo json_encode(['logs' => [], 'error' => 'Query failed: ' . $res['code']]);
    exit;
}

$logs = [];
foreach ($res['body'] as $row) {
    if (!isset($row['document'])) continue;
    $fields = $row['document']['fields'] ?? [];

    $ts = null;
    if (isset($fields['timestamp']['timestampValue'])) {
        $ts = $fields['timestamp']['timestampValue'];
    }

    $logs[] = [
        'customerId'  => $fields['customerId']['stringValue']  ?? '',
        'userId'      => $fields['userId']['stringValue']      ?? '',
        'pageTitle'   => $fields['pageTitle']['stringValue']   ?? 'Unknown Form',
        'pageUrl'     => $fields['pageUrl']['stringValue']     ?? '',
        'portalName'  => $fields['portalName']['stringValue']  ?? '',
        'fieldsCount' => (int)($fields['fieldsCount']['integerValue'] ?? $fields['fieldsCount']['doubleValue'] ?? 0),
        'cost'        => (float)($fields['cost']['doubleValue'] ?? $fields['cost']['integerValue'] ?? 0),
        'timestamp'   => $ts,
    ];
}

// Summary stats
$totalFields = array_sum(array_column($logs, 'fieldsCount'));
$totalCost   = array_sum(array_column($logs, 'cost'));

echo json_encode([
    'success'     => true,
    'count'       => count($logs),
    'totalFields' => $totalFields,
    'totalCost'   => round($totalCost, 2),
    'logs'        => $logs,
]);
?>
