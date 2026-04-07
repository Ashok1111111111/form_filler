<?php
// Firestore REST API helper — no gRPC required, uses Firebase anonymous auth + curl

// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

const FIREBASE_PROJECT   = 'form-filling-service-5a261';
const FIREBASE_WEB_API_KEY_FALLBACK = 'AIzaSyCGnA0-2QiSQ2Dg8n3xhzBxjvVKcXKf9P0';
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: FIREBASE_WEB_API_KEY_FALLBACK);
const FIRESTORE_BASE     = 'https://firestore.googleapis.com/v1/projects/' . FIREBASE_PROJECT . '/databases/(default)/documents';
const TOKEN_CACHE_FILE   = '/tmp/fb_anon_token.json';

function getRequestBearerToken(): ?string {
    static $resolved = false;
    static $token = null;

    if ($resolved) return $token;
    $resolved = true;

    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }

    $authHeader = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (preg_match('/Bearer\s+(.+)/i', trim((string)$authHeader), $matches)) {
        $token = trim($matches[1]);
    }

    return $token;
}

// ── GET AUTH TOKEN (anonymous sign-in, cached 55 min) ──────────────────────
function getFirebaseToken(): ?string {
    $requestToken = getRequestBearerToken();
    if ($requestToken) return $requestToken;

    // Check cache
    if (file_exists(TOKEN_CACHE_FILE)) {
        $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
        if ($cached && isset($cached['token']) && $cached['expiry'] > time()) {
            return $cached['token'];
        }
    }
    // Sign up anonymously via Firebase Auth REST API
    $ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . FIREBASE_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['returnSecureToken' => true]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (!isset($data['idToken'])) return null;

    file_put_contents(TOKEN_CACHE_FILE, json_encode([
        'token'  => $data['idToken'],
        'expiry' => time() + 55 * 60
    ]));
    return $data['idToken'];
}

// ── CURL HELPER ─────────────────────────────────────────────────────────────
function fsRequest(string $method, string $url, ?array $body = null): array {
    $token   = getFirebaseToken();
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $res  = curl_exec($ch);
    $curlErr = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code' => $code,
        'body' => json_decode($res, true) ?? [],
        'raw' => $res,
        'curl_error' => $curlErr,
        'token_present' => (bool)$token,
    ];
}

// ── PARSE FIRESTORE FIELD VALUE ──────────────────────────────────────────────
function parseField(array $f): mixed {
    if (isset($f['stringValue']))  return $f['stringValue'];
    if (isset($f['integerValue'])) return (int)$f['integerValue'];
    if (isset($f['doubleValue']))  return (float)$f['doubleValue'];
    if (isset($f['booleanValue'])) return (bool)$f['booleanValue'];
    if (isset($f['nullValue']))    return null;
    if (isset($f['mapValue']))     return parseFields($f['mapValue']['fields'] ?? []);
    if (isset($f['arrayValue'])) {
        return array_map('parseField', $f['arrayValue']['values'] ?? []);
    }
    return null;
}

function parseFields(array $fields): array {
    $out = [];
    foreach ($fields as $k => $v) $out[$k] = parseField($v);
    return $out;
}

function parseDoc(array $doc): ?array {
    if (!isset($doc['fields'])) return null;
    return parseFields($doc['fields']);
}

// ── ENCODE VALUE FOR WRITE ───────────────────────────────────────────────────
function encodeValue(mixed $v): array {
    if (is_null($v))    return ['nullValue' => null];
    if (is_bool($v))    return ['booleanValue' => $v];
    if (is_int($v))     return ['integerValue' => (string)$v];
    if (is_float($v))   return ['doubleValue' => $v];
    if (is_string($v))  return ['stringValue' => $v];
    if (is_array($v))   return ['mapValue' => ['fields' => encodeFields($v)]];
    return ['stringValue' => (string)$v];
}

function encodeFields(array $data): array {
    $fields = [];
    foreach ($data as $k => $v) $fields[$k] = encodeValue($v);
    return $fields;
}

// ── GET DOCUMENT ─────────────────────────────────────────────────────────────
function getDoc(string $collection, string $docId): ?array {
    $res = fsRequest('GET', FIRESTORE_BASE . "/$collection/$docId");
    if ($res['code'] !== 200) return null;
    return parseDoc($res['body']);
}

// ── UPDATE DOCUMENT (partial patch) ─────────────────────────────────────────
function updateDoc(string $collection, string $docId, array $data): bool {
    $fields    = encodeFields($data);
    $maskNames = implode('&updateMask.fieldPaths=', array_map('urlencode', array_keys($data)));
    $url       = FIRESTORE_BASE . "/$collection/$docId?updateMask.fieldPaths=$maskNames";
    $res       = fsRequest('PATCH', $url, ['fields' => $fields]);
    return $res['code'] === 200;
}

function updateDocDetailed(string $collection, string $docId, array $data): array {
    $fields    = encodeFields($data);
    $maskNames = implode('&updateMask.fieldPaths=', array_map('urlencode', array_keys($data)));
    $url       = FIRESTORE_BASE . "/$collection/$docId?updateMask.fieldPaths=$maskNames";
    $res       = fsRequest('PATCH', $url, ['fields' => $fields]);
    $res['success'] = ($res['code'] === 200);
    return $res;
}

// ── SET DOCUMENT (known ID, creates or overwrites) ──────────────────────────
function addDocWithId(string $collection, string $docId, array $data): bool {
    $fields = encodeFields($data);
    $res    = fsRequest('PATCH', FIRESTORE_BASE . "/$collection/$docId", ['fields' => $fields]);
    return $res['code'] === 200;
}

function addDocWithIdDetailed(string $collection, string $docId, array $data): array {
    $fields = encodeFields($data);
    $res    = fsRequest('PATCH', FIRESTORE_BASE . "/$collection/$docId", ['fields' => $fields]);
    $res['success'] = ($res['code'] === 200);
    return $res;
}

// ── ADD DOCUMENT (auto-ID) ───────────────────────────────────────────────────
function addDoc(string $collection, array $data): bool {
    $data['timestamp'] = date('Y-m-d\TH:i:s\Z', time()); // ISO UTC string → stored as stringValue
    $fields = encodeFields($data);
    $res    = fsRequest('POST', FIRESTORE_BASE . "/$collection", ['fields' => $fields]);
    return $res['code'] === 200;
}

function addDocDetailed(string $collection, array $data): array {
    $data['timestamp'] = date('Y-m-d\TH:i:s\Z', time());
    $fields = encodeFields($data);
    $res    = fsRequest('POST', FIRESTORE_BASE . "/$collection", ['fields' => $fields]);
    $res['success'] = ($res['code'] === 200);
    return $res;
}

// ── QUERY (structured query) ─────────────────────────────────────────────────
function queryWhere(string $collection, string $field, string $op, mixed $value, int $limit = 1): array {
    $opMap = ['=' => 'EQUAL', '<' => 'LESS_THAN', '>' => 'GREATER_THAN'];
    $res = fsRequest('POST', FIRESTORE_BASE . ':runQuery', [
        'structuredQuery' => [
            'from'  => [['collectionId' => $collection]],
            'where' => ['fieldFilter' => [
                'field' => ['fieldPath' => $field],
                'op'    => $opMap[$op] ?? 'EQUAL',
                'value' => encodeValue($value)
            ]],
            'limit' => $limit
        ]
    ]);
    if ($res['code'] !== 200) return [];
    $results = [];
    foreach ((array)$res['body'] as $item) {
        if (isset($item['document'])) {
            $results[] = parseDoc($item['document']);
        }
    }
    return $results;
}
?>
