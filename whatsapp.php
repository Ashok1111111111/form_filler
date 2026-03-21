<?php
// ─────────────────────────────────────────────────────────────────────────────
// WhatsApp Business Cloud API Proxy
// AI Form Filler – ai-workflows.cloud
// ─────────────────────────────────────────────────────────────────────────────

// ─── CORS ─────────────────────────────────────────────────────────────────────
$allowedOrigin = 'https://ai-workflows.cloud';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
} else {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── CONFIG ───────────────────────────────────────────────────────────────────
define('WA_TOKEN',        'YOUR_PERMANENT_ACCESS_TOKEN');
define('WA_PHONE_ID',     'YOUR_PHONE_NUMBER_ID');
define('WA_VERIFY_TOKEN', 'aiform_webhook_secret_2024');
define('ADMIN_PHONE',     '917999614511');
define('WA_API_URL',      'https://graph.facebook.com/v19.0/' . WA_PHONE_ID . '/messages');

// ─── WEBHOOK VERIFICATION (GET) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    if ($mode === 'subscribe' && $token === WA_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
    }
    exit;
}

// ─── HELPER: Send WA Message via cURL ─────────────────────────────────────────
function sendWA(array $payload): array {
    $ch = curl_init(WA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . WA_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true)];
}

// ─── HELPER: Send plain text message ──────────────────────────────────────────
function sendText(string $to, string $text): array {
    return sendWA([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['body' => $text],
    ]);
}

// ─── HELPER: Send template message ────────────────────────────────────────────
function sendTemplate(string $to, string $templateName, array $components = [], string $langCode = 'en'): array {
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'     => $templateName,
            'language' => ['code' => $langCode],
        ],
    ];
    if (!empty($components)) {
        $payload['template']['components'] = $components;
    }
    return sendWA($payload);
}

// ─── HELPER: Normalize phone to E.164 (91XXXXXXXXXX) ─────────────────────────
function cleanPhone(string $phone): string {
    $p = preg_replace('/\D/', '', $phone);
    if (strlen($p) === 10) $p = '91' . $p;
    return $p;
}

// ─── HELPER: Error response ────────────────────────────────────────────────────
function respondError(string $msg): void {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $msg]);
}

// ─── INCOMING WEBHOOK HANDLER ─────────────────────────────────────────────────
function handleIncoming(array $data): void {
    try {
        $entry   = $data['entry'][0]    ?? [];
        $changes = $entry['changes'][0] ?? [];
        $value   = $changes['value']    ?? [];
        $msgs    = $value['messages']   ?? [];
        if (empty($msgs)) return;

        $msg  = $msgs[0];
        $from = $msg['from'] ?? '';
        $type = $msg['type'] ?? '';

        $rawText = '';
        if ($type === 'text') {
            $rawText = trim($msg['text']['body'] ?? '');
        }
        if (!$from || !$rawText) return;

        $text = strtoupper($rawText);

        // ── Keyword routing ───────────────────────────────────────────────
        if (preg_match('/^(HI|HELLO|NAMASTE|HAI|HEY)\b/', $text)) {
            $reply  = "🙏 *Welcome to AI Form Filler!*\n\n";
            $reply .= "Reply with a keyword to get help:\n\n";
            $reply .= "📖 *HELP* — How to use guide\n";
            $reply .= "🔧 *INSTALL* — Extension install steps\n";
            $reply .= "💰 *PRICING* — Credit packages & rates\n";
            $reply .= "💳 *RECHARGE* — Add credits via UPI\n";
            $reply .= "🏛️ *PORTALS* — Supported form portals\n";
            $reply .= "🐛 *ERROR* — Troubleshooting guide\n";
            $reply .= "🆘 *SUPPORT* — Talk to our team\n";
            $reply .= "🚫 *STOP* — Unsubscribe from promos\n\n";
            $reply .= "👉 Visit: *ai-workflows.cloud*";
            sendText($from, $reply);

        } elseif ($text === 'HELP') {
            $reply  = "📖 *How to Use AI Form Filler*\n\n";
            $reply .= "*Step 1: Install Extension*\n";
            $reply .= "Download from: ai-workflows.cloud/download-extension.html\n\n";
            $reply .= "*Step 2: Add Customer Profile*\n";
            $reply .= "Go to 'Create Profile' → fill all 112 fields\n\n";
            $reply .= "*Step 3: Open Any Government Form*\n";
            $reply .= "Navigate to SSC, UPSC, Railways, IBPS etc.\n\n";
            $reply .= "*Step 4: Click Fill!*\n";
            $reply .= "Open extension → select customer → click Auto Fill\n";
            $reply .= "⚡ Done in under 5 seconds!\n\n";
            $reply .= "💰 Each fill = 1 credit. New users get *50 FREE credits!*";
            sendText($from, $reply);

        } elseif ($text === 'INSTALL') {
            $reply  = "🔧 *Chrome Extension Install Steps*\n\n";
            $reply .= "1. Open: ai-workflows.cloud/download-extension.html\n";
            $reply .= "2. Click 'Download Extension' → save ZIP file\n";
            $reply .= "3. Extract the ZIP to a folder\n";
            $reply .= "4. Open Chrome → go to chrome://extensions\n";
            $reply .= "5. Enable *Developer Mode* (toggle, top-right)\n";
            $reply .= "6. Click *Load unpacked* → select extracted folder\n";
            $reply .= "7. Pin extension to Chrome toolbar 📌\n\n";
            $reply .= "*After install:*\n";
            $reply .= "• Login with your AI Form Filler credentials\n";
            $reply .= "• Open any govt form → click extension → Fill!\n\n";
            $reply .= "Issues? Reply *SUPPORT* 🆘";
            sendText($from, $reply);

        } elseif ($text === 'PRICING') {
            $reply  = "💰 *Credit Packages & Pricing*\n\n";
            $reply .= "📦 *Starter* — 10 credits = ₹50\n";
            $reply .= "   ₹5 per form fill\n\n";
            $reply .= "🔥 *Basic* — 25 credits = ₹100 *(Popular)*\n";
            $reply .= "   ₹4 per form fill | Save 20%\n\n";
            $reply .= "⭐ *Premium* — 50 credits = ₹150 *(Best Value)*\n";
            $reply .= "   ₹3 per form fill | Save 40%\n\n";
            $reply .= "💎 *Bulk* — 100 credits = ₹250\n";
            $reply .= "   ₹2.5 per form fill | Save 50%\n\n";
            $reply .= "🎁 New users get *50 FREE credits* on signup!\n\n";
            $reply .= "Reply *RECHARGE* to add credits now 👇";
            sendText($from, $reply);

        } elseif ($text === 'RECHARGE') {
            $reply  = "💳 *How to Recharge Credits*\n\n";
            $reply .= "*Step 1:* Visit ai-workflows.cloud/recharge.html\n\n";
            $reply .= "*Step 2:* Select a package:\n";
            $reply .= "• 10cr=₹50 | 25cr=₹100 | 50cr=₹150 | 100cr=₹250\n\n";
            $reply .= "*Step 3:* Pay via UPI:\n";
            $reply .= "📲 UPI ID: *7999614511@pthdfc*\n";
            $reply .= "👤 Name: Ashok Kumar\n";
            $reply .= "✅ Supports: Paytm, PhonePe, GPay, BHIM\n\n";
            $reply .= "*Step 4:* Enter your UPI Transaction ID on the recharge page and submit.\n\n";
            $reply .= "⏳ Credits added within *5–10 minutes* after verification!";
            sendText($from, $reply);

        } elseif ($text === 'PORTALS') {
            $reply  = "🏛️ *Supported Government Form Portals*\n\n";
            $reply .= "✅ *Central Exams:*\n";
            $reply .= "• SSC (Staff Selection Commission)\n";
            $reply .= "• UPSC (Civil Services / IAS)\n";
            $reply .= "• Railways (RRB / RRC)\n";
            $reply .= "• IBPS (Banking — PO, Clerk, SO)\n";
            $reply .= "• SBI PO & Clerk\n";
            $reply .= "• NTA (JEE, NEET, UGC NET, CUET)\n\n";
            $reply .= "✅ *State Level:*\n";
            $reply .= "• State PSCs (All states)\n";
            $reply .= "• State Police Recruitment\n";
            $reply .= "• State Revenue & Patwari\n\n";
            $reply .= "✅ *Defence & Others:*\n";
            $reply .= "• Army, Navy, Air Force recruitment\n";
            $reply .= "• High Court Staff\n";
            $reply .= "• Municipal Corporation\n\n";
            $reply .= "Works on *any HTML form* portal!\n";
            $reply .= "Portal not listed? Reply *SUPPORT* 🙏";
            sendText($from, $reply);

        } elseif ($text === 'ERROR') {
            $reply  = "🐛 *Troubleshooting Guide*\n\n";
            $reply .= "*Extension not working?*\n";
            $reply .= "• Refresh form page and try again\n";
            $reply .= "• Check you're logged in to extension\n";
            $reply .= "• Verify you have credits remaining\n";
            $reply .= "• Disable other extensions temporarily\n\n";
            $reply .= "*Form not filling correctly?*\n";
            $reply .= "• Ensure customer profile is complete (all fields)\n";
            $reply .= "• Wait for page to fully load before clicking Fill\n";
            $reply .= "• Try refreshing the form page\n\n";
            $reply .= "*Credits not added after recharge?*\n";
            $reply .= "• Wait 5–10 minutes for manual verification\n";
            $reply .= "• Double-check transaction ID is correct\n";
            $reply .= "• Contact support if not added within 30 mins\n\n";
            $reply .= "Still stuck? Reply *SUPPORT* 🆘";
            sendText($from, $reply);

        } elseif ($text === 'SUPPORT') {
            // Forward to admin
            $fwdMsg  = "🆘 *SUPPORT REQUEST*\n\n";
            $fwdMsg .= "📞 From: +{$from}\n";
            $fwdMsg .= "💬 Msg: {$rawText}\n";
            $fwdMsg .= "🕐 " . date('d M Y, h:i A') . ' IST';
            sendText(ADMIN_PHONE, $fwdMsg);

            $reply  = "🆘 *Support Request Received!*\n\n";
            $reply .= "Your message has been forwarded to our team.\n\n";
            $reply .= "⏰ We respond within *1–2 hours* (9 AM – 9 PM IST)\n\n";
            $reply .= "Meanwhile try:\n";
            $reply .= "• Reply *HELP* — usage guide\n";
            $reply .= "• Reply *ERROR* — troubleshooting\n\n";
            $reply .= "📧 Email: support@ai-workflows.cloud";
            sendText($from, $reply);

        } elseif ($text === 'STOP') {
            $reply  = "🚫 *Unsubscribed from Promotions*\n\n";
            $reply .= "You won't receive promotional messages anymore.\n\n";
            $reply .= "✅ You'll still get:\n";
            $reply .= "• Recharge confirmations\n";
            $reply .= "• Credit alerts\n";
            $reply .= "• Important account notifications\n\n";
            $reply .= "To resubscribe, reply *START* anytime. 🙏";
            sendText($from, $reply);
            sendText(ADMIN_PHONE, "📵 User +{$from} unsubscribed from promos.");

        } else {
            // Forward unknown message to admin
            $fwdMsg  = "💬 *Unhandled Message*\n\n";
            $fwdMsg .= "📞 From: +{$from}\n";
            $fwdMsg .= "💬 Msg: {$rawText}\n";
            $fwdMsg .= "🕐 " . date('d M Y, h:i A') . ' IST';
            sendText(ADMIN_PHONE, $fwdMsg);

            // Default reply
            $reply  = "👋 Thanks for messaging AI Form Filler!\n\n";
            $reply .= "Reply with a keyword:\n\n";
            $reply .= "• *HELP* — usage guide\n";
            $reply .= "• *PRICING* — plans & rates\n";
            $reply .= "• *RECHARGE* — add credits\n";
            $reply .= "• *PORTALS* — supported portals\n";
            $reply .= "• *SUPPORT* — talk to our team\n\n";
            $reply .= "🌐 ai-workflows.cloud 🚀";
            sendText($from, $reply);
        }

    } catch (Exception $e) {
        error_log('WA webhook error: ' . $e->getMessage());
    }
}

// ─── POST HANDLER ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        respondError('Invalid JSON');
        exit;
    }

    // Incoming webhook from Meta
    if (isset($data['object']) && $data['object'] === 'whatsapp_business_account') {
        handleIncoming($data);
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Outgoing action from our app
    $action = $data['action'] ?? '';
    $result = [];

    switch ($action) {

        // ── Welcome new user ───────────────────────────────────────────────
        case 'welcome':
            $phone  = cleanPhone($data['phone']  ?? '');
            $name   = $data['name']   ?? 'User';
            $wallet = $data['credits'] ?? '₹100'; // 'credits' key kept for compat, value is wallet amount
            if (!$phone) { respondError('phone required'); exit; }

            $msg  = "🎉 *Welcome to AI Form Filler, {$name}!*\n\n";
            $msg .= "✅ Your account is active\n";
            $msg .= "💰 *{$wallet} FREE wallet balance* added!\n\n";
            $msg .= "📝 *What you can do:*\n";
            $msg .= "• Fill 60–80 form fields in under 5 seconds\n";
            $msg .= "• Manage 100s of customers easily\n";
            $msg .= "• Works on SSC, UPSC, Railways, IBPS & more\n\n";
            $msg .= "🚀 *Get started:*\n";
            $msg .= "1. Install Chrome Extension\n";
            $msg .= "2. Add a customer profile\n";
            $msg .= "3. Open any form → click Fill!\n\n";
            $msg .= "Reply *HELP* anytime for assistance 🙏\n";
            $msg .= "🌐 ai-workflows.cloud";
            $result = sendText($phone, $msg);
            break;

        // ── Low credits alert ──────────────────────────────────────────────
        case 'low_credits':
            $phone   = cleanPhone($data['phone']   ?? '');
            $name    = $data['name']    ?? 'User';
            $credits = $data['credits'] ?? '0';
            if (!$phone) { respondError('phone required'); exit; }

            $msg  = "⚠️ *Low Credits Alert!*\n\n";
            $msg .= "Hi {$name}, you have only *{$credits} credit(s)* left.\n\n";
            $msg .= "💳 *Recharge to continue filling forms:*\n";
            $msg .= "• Starter: 10 credits = ₹50\n";
            $msg .= "• Basic: 25 credits = ₹100 🔥\n";
            $msg .= "• Premium: 50 credits = ₹150\n";
            $msg .= "• Bulk: 100 credits = ₹250\n\n";
            $msg .= "📲 UPI: *7999614511@pthdfc*\n";
            $msg .= "👉 Reply *RECHARGE* for steps";
            $result = sendText($phone, $msg);
            break;

        // ── Recharge received — notify user + admin ────────────────────────
        case 'recharge_received':
            $phone   = cleanPhone($data['phone']   ?? '');
            $name    = $data['name']    ?? 'User';
            $package = $data['package'] ?? '';
            $amount  = $data['amount']  ?? '';
            $txnId   = $data['txn_id']  ?? '';

            // Notify user if phone available
            if ($phone) {
                $userMsg  = "✅ *Recharge Request Received!*\n\n";
                $userMsg .= "Hi {$name}, we received your payment:\n";
                $userMsg .= "💰 Amount: ₹{$amount}\n";
                $userMsg .= "📦 Package: {$package} credits\n";
                $userMsg .= "🔖 Txn ID: {$txnId}\n\n";
                $userMsg .= "⏳ Credits will be added within *5–10 minutes* after manual verification.\n";
                $userMsg .= "We'll notify you once done! 🙏";
                sendText($phone, $userMsg);
            }

            // Always notify admin
            $adminMsg  = "🔔 *NEW RECHARGE REQUEST*\n\n";
            $adminMsg .= "👤 Name: {$name}\n";
            $adminMsg .= "📞 Phone: " . ($phone ?: 'N/A') . "\n";
            $adminMsg .= "💰 Amount: ₹{$amount}\n";
            $adminMsg .= "📦 Credits: {$package}\n";
            $adminMsg .= "🔖 Txn ID: {$txnId}\n\n";
            $adminMsg .= "👉 Approve at: admin-recharge-approvals.html";
            $result = sendText(ADMIN_PHONE, $adminMsg);
            break;

        // ── Credits added — notify user after admin approves ───────────────
        case 'credits_added':
            $phone = cleanPhone($data['phone'] ?? '');
            $name  = $data['name']  ?? 'User';
            $added = $data['added'] ?? '';
            $total = $data['total'] ?? '';
            if (!$phone) { respondError('phone required'); exit; }

            $msg  = "🎊 *Credits Added Successfully!*\n\n";
            $msg .= "Hi {$name}, your recharge is confirmed:\n\n";
            $msg .= "✅ *+{$added} credits* added\n";
            $msg .= "💰 New balance: *{$total} credits*\n\n";
            $msg .= "You can now fill more forms! 🚀\n";
            $msg .= "Need help? Reply *HELP* 🙏";
            $result = sendText($phone, $msg);
            break;

        // ── Error alert to admin ───────────────────────────────────────────
        case 'error_alert':
            $error   = $data['error']   ?? 'Unknown error';
            $context = $data['context'] ?? '';

            $msg  = "🚨 *AI Form Filler Error Alert*\n\n";
            $msg .= "❌ Error: {$error}\n";
            if ($context) $msg .= "📍 Context: {$context}\n";
            $msg .= "\n🕐 " . date('d M Y, h:i A') . ' IST';
            $result = sendText(ADMIN_PHONE, $msg);
            break;

        // ── Broadcast promo to multiple users ──────────────────────────────
        case 'broadcast':
            $phones  = $data['phones']  ?? [];
            $message = $data['message'] ?? '';
            if (empty($phones) || !$message) {
                respondError('phones[] array and message are required');
                exit;
            }
            $sent = 0; $failed = 0;
            foreach ($phones as $ph) {
                $p = cleanPhone($ph);
                if (!$p) { $failed++; continue; }
                $r = sendText($p, $message);
                $r['code'] === 200 ? $sent++ : $failed++;
                usleep(300000); // 300ms delay to avoid rate limiting
            }
            $result = ['sent' => $sent, 'failed' => $failed, 'total' => count($phones)];
            break;

        default:
            respondError('Unknown action: ' . htmlspecialchars($action));
            exit;
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'result' => $result]);
    exit;
}

// Fallback
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
