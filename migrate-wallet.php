<?php
// ONE-TIME MIGRATION: adds walletBalance field to all users who don't have it
// Run once: https://ai-workflows.cloud/migrate-wallet.php?key=ashok2025
// DELETE this file after running!

header('Content-Type: text/html; charset=utf-8');

$SECRET_KEY = 'ashok2025';
if (($_GET['key'] ?? '') !== $SECRET_KEY) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2>');
}

require_once __DIR__ . '/firestore-helper.php';

const RUPEES_PER_CREDIT = 4;  // 1 old credit → ₹4
const FREE_BALANCE      = 100.0; // ₹100 free for users with 0 credits

// Fetch all users via Firestore REST
$token   = getFirebaseToken();
$headers = ['Content-Type: application/json'];
if ($token) $headers[] = 'Authorization: Bearer ' . $token;

$ch = curl_init(FIRESTORE_BASE . '/users');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 30,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = json_decode($res, true);

echo "<h2>🔄 Wallet Migration</h2>";

if ($code !== 200 || !isset($json['documents'])) {
    echo "<pre style='color:red'>Error ($code): " . htmlspecialchars($res) . "</pre>";
    exit;
}

$updated = 0;
$skipped = 0;
$log     = [];

foreach ($json['documents'] as $rawDoc) {
    $docName = $rawDoc['name'] ?? '';
    $uid     = basename($docName);
    $doc     = parseDoc($rawDoc);

    if ($doc === null) continue;

    // Skip if already migrated
    if (isset($doc['walletBalance'])) {
        $skipped++;
        $log[] = "⏭️  SKIP $uid — already has ₹" . $doc['walletBalance'];
        continue;
    }

    $oldCredits    = (int)($doc['credits'] ?? 0);
    $walletBalance = $oldCredits > 0
        ? round($oldCredits * RUPEES_PER_CREDIT, 2)
        : FREE_BALANCE;

    $ok = updateDoc('users', $uid, ['walletBalance' => $walletBalance]);
    if ($ok) {
        $updated++;
        $name  = $doc['name'] ?? 'unknown';
        $log[] = "✅ $uid ($name) — {$oldCredits} credits → ₹{$walletBalance}";
    } else {
        $log[] = "❌ FAILED $uid";
    }
}

echo "<p><strong>Updated:</strong> $updated &nbsp;|&nbsp; <strong>Skipped:</strong> $skipped</p>";
echo "<pre style='background:#f4f4f4;padding:1rem;border-radius:8px;font-size:13px'>" . implode("\n", $log) . "</pre>";
echo "<hr><p style='color:red'><strong>⚠️ DELETE this file now: rm /var/www/clawbot-website/migrate-wallet.php</strong></p>";
?>
