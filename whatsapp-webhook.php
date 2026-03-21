<?php
/**
 * WhatsApp Cloud API Chatbot — AI Workflows / Er. Ashok Kumar
 * https://ai-workflows.cloud
 */

// ── CONFIG ──────────────────────────────────────────────────────────────────
define('VERIFY_TOKEN',    'aiformfiller_webhook_2024');
define('WA_TOKEN',        'EAAM7uPGvKyUBQ2xwsazWU28QmNo1p0ru5miJPjl8RFZCKop514kZAauSLz1XZByOClq5BHd8zOtf1P1FLYcVFov6beDjBaQcJK3SX4krb3fsAgasWkcDuQg3zS252LUH75y1jOS6WAzhA28TZA44ELGjjMzB5ZBLpvvaGxPPsooXmwL8sSzyPZAdnx05oMoQZDZD');
define('PHONE_NUMBER_ID', '994897957041699');
define('WA_API_URL',      'https://graph.facebook.com/v19.0/' . PHONE_NUMBER_ID . '/messages');

// ── WEBHOOK VERIFICATION (GET) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (
        isset($_GET['hub_mode'], $_GET['hub_verify_token'], $_GET['hub_challenge']) &&
        $_GET['hub_mode'] === 'subscribe' &&
        $_GET['hub_verify_token'] === VERIFY_TOKEN
    ) {
        http_response_code(200);
        echo $_GET['hub_challenge'];
    } else {
        http_response_code(403);
        echo 'Forbidden';
    }
    exit;
}

// ── INCOMING MESSAGE (POST) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $msg   = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
    if (!$msg) { http_response_code(200); echo 'OK'; exit; }

    $from  = $msg['from'];
    $type  = $msg['type'];
    $text  = '';

    if ($type === 'text') {
        $text = trim(strtolower($msg['text']['body'] ?? ''));
    } elseif ($type === 'interactive') {
        $text = $msg['interactive']['button_reply']['id'] ?? '';
    }

    markRead($msg['id']);

    $reply = getChatbotReply($text);
    if ($reply) sendTextMessage($from, $reply);

    http_response_code(200);
    echo 'OK';
    exit;
}

// ── CHATBOT LOGIC ─────────────────────────────────────────────────────────────
function getChatbotReply(string $text): string {

    // Main menu triggers
    if (preg_match('/^(hi|hello|helo|hey|namaste|namaskar|hii+|start|menu|help|0|\?)$/i', $text) || $text === '') {
        return mainMenu();
    }

    // ── OPTION 1: About Ashok Kumar
    if ($text === '1' || preg_match('/about|kaun|who|ashok|er\.|engineer|qualification|experience|location|contact/i', $text)) {
        return replyAbout();
    }

    // ── OPTION 2: Services & Pricing
    if ($text === '2' || preg_match('/service|website|web app|android|chrome|extension|automation|n8n|ai automat|workflow|develop|build|bana|price|pricing|cost|charge|kitna|rate|fees/i', $text)) {
        return replyServices();
    }

    // ── OPTION 3: Live Products
    if ($text === '3' || preg_match('/product|portfolio|project|bhuvan|photo editor|pdf|form filler|kya banaya|app/i', $text)) {
        return replyProducts();
    }

    // ── OPTION 4: AI Form Filler Support
    if ($text === '4' || preg_match('/support|install|extension|form fill|credit|recharge|customer id|nahi ho raha|error|problem/i', $text)) {
        return replySupportMenu();
    }

    // ── OPTION 5: Get a Quote
    if ($text === '5' || preg_match('/quote|consult|discuss|project|requirement|hire|kaam|freelance/i', $text)) {
        return replyQuote();
    }

    // ── Support sub-options
    if ($text === '4a' || preg_match('/install nahi/i', $text)) return replyInstall();
    if ($text === '4b' || preg_match('/form nahi|fill nahi/i', $text)) return replyFormFill();
    if ($text === '4c') return replyRecharge();
    if ($text === '4d') return replyCustomerId();

    // ── Thanks / Bye
    if (preg_match('/thank|shukriya|dhanyawad|done|solved|ho gaya|theek|bye|goodbye/i', $text)) {
        return replyThanks();
    }

    // Default
    return "Mujhe samajh nahi aaya 🙏\n\n" . mainMenu();
}

// ── REPLY TEMPLATES ───────────────────────────────────────────────────────────

function mainMenu(): string {
    return "Namaste! 🙏 *AI Workflows* mein swagat hai.\n"
         . "Main Er. Ashok Kumar ka digital assistant hoon.\n\n"
         . "Kaise help karoon? Number bhejein:\n\n"
         . "1️⃣  Er. Ashok Kumar ke baare mein\n"
         . "2️⃣  Services & Pricing\n"
         . "3️⃣  Live Products & Portfolio\n"
         . "4️⃣  AI Form Filler Support\n"
         . "5️⃣  Quote / Project Discussion\n\n"
         . "👉 Sirf number bhejein ya apna sawaal likhein\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyAbout(): string {
    return "👨‍💻 *Er. Ashok Kumar — Full-Stack Developer*\n\n"
         . "🎓 *Qualification:* B.E. (Computer Science)\n"
         . "💼 *Experience:* 3.5 years at *DailyHunt (Josh App)*\n"
         . "   India's leading short video & content platform\n"
         . "📍 *Location:* Patna, Bihar, India\n\n"
         . "🚀 *Expertise:*\n"
         . "   • Full-Stack Web Development\n"
         . "   • AI Automation & Workflows\n"
         . "   • Chrome Extension Development\n"
         . "   • Android App Development\n\n"
         . "🌐 *Website:* ai-workflows.cloud\n"
         . "📞 *WhatsApp:* +91 92969 14511\n\n"
         . "\"Er.\" title Engineers ke liye hota hai, jaise doctors ke liye \"Dr.\" 😊\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyServices(): string {
    return "🛠️ *Services & Pricing — AI Workflows*\n\n"
         . "*5 Core Services:*\n\n"
         . "🌐 *1. Website Design & Development*\n"
         . "   Modern, responsive, SEO-friendly websites\n"
         . "   Timeline: 3–7 days\n\n"
         . "💻 *2. Web Application Development*\n"
         . "   Dashboards, SaaS, portals, admin panels\n"
         . "   Timeline: 1–4 weeks\n\n"
         . "🤖 *3. AI Automation & Workflows*\n"
         . "   n8n automation, email/lead/data workflows\n"
         . "   Timeline: 1–5 days\n\n"
         . "🧩 *4. Chrome Extension Development*\n"
         . "   Custom browser tools & automation\n"
         . "   Timeline: 1–2 weeks\n\n"
         . "📱 *5. Android App Development*\n"
         . "   Business apps, live streaming, utilities\n"
         . "   Timeline: 2–6 weeks\n\n"
         . "💰 *Pricing:* Project ke scope ke hisaab se custom quote\n"
         . "📞 Free consultation: +91 92969 14511\n"
         . "💳 Advance: 30–50% | Balance on delivery\n"
         . "💸 Payment: UPI / Bank Transfer\n\n"
         . "Quote ke liye *5* bhejein 👇\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyProducts(): string {
    return "🚀 *Live Products & Portfolio*\n\n"
         . "Er. Ashok Kumar ke 4 live products:\n\n"
         . "🙏 *1. Bhuvan Bhaskar Dham App*\n"
         . "   Android app for live religious event broadcasting\n"
         . "   Users watch live ceremonies on mobile\n\n"
         . "🤖 *2. AI Form Filler*\n"
         . "   Chrome extension — government form auto-fill\n"
         . "   SSC, UPSC, Railways, IBPS portals\n"
         . "   ₹5 per form | Cyber cafe ke liye perfect\n"
         . "   👉 ai-workflows.cloud\n\n"
         . "🖼️ *3. Photo Editor*\n"
         . "   Online browser-based image editing tool\n"
         . "   Crop, resize, filters — no software needed\n\n"
         . "📄 *4. PDF & Document Editor*\n"
         . "   Browser-based PDF editing & merging tool\n"
         . "   No installation required\n\n"
         . "🌐 Full portfolio: *ai-workflows.cloud*\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replySupportMenu(): string {
    return "🛟 *AI Form Filler Support*\n\n"
         . "Apni problem select karein:\n\n"
         . "4️⃣A  Extension install nahi ho rahi\n"
         . "4️⃣B  Form fill nahi ho raha\n"
         . "4️⃣C  Credits recharge karna hai\n"
         . "4️⃣D  Customer ID kahan milega\n\n"
         . "👉 *4a*, *4b*, *4c* ya *4d* bhejein\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyInstall(): string {
    return "📥 *Extension Install Guide*\n\n"
         . "*Step 1:* Link kholein:\n"
         . "👉 ai-workflows.cloud/download-extension.html\n\n"
         . "*Step 2:* \"Download Extension\" click karein\n\n"
         . "*Step 3:* Chrome mein:\n"
         . "chrome://extensions/ kholein\n\n"
         . "*Step 4:* Top-right → *Developer Mode* ON karein\n\n"
         . "*Step 5:* \"Load unpacked\" → downloaded folder select karein\n\n"
         . "✅ Extension install ho jayegi!\n\n"
         . "Problem ho? Screenshot bhejein 📸\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyFormFill(): string {
    return "📝 *Form Fill Guide*\n\n"
         . "*Check karein:*\n"
         . "✅ Customer ID sahi? (CUS- se start hoga)\n"
         . "✅ \"Load Customer Data\" dabaya?\n"
         . "✅ Name/Email preview dikh raha?\n\n"
         . "*Nahi dikh raha?*\n"
         . "1. Page reload karein (F5)\n"
         . "2. Extension popup band karke dobara kholein\n"
         . "3. Customer ID dobara enter karein\n\n"
         . "*403 error?*\n"
         . "Latest version download karein:\n"
         . "👉 ai-workflows.cloud/download-extension.html\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyRecharge(): string {
    return "💳 *Credits Recharge Steps*\n\n"
         . "*Step 1:* UPI payment karein:\n"
         . "📲 *UPI ID:* 9296914511@pthdfc\n\n"
         . "*Packages:*\n"
         . "• ₹10 = 2 credits\n"
         . "• ₹25 = 6 credits\n"
         . "• ₹50 = 14 credits\n\n"
         . "*Step 2:* Screenshot upload karein:\n"
         . "👉 ai-workflows.cloud/recharge.html\n\n"
         . "*Step 3:* 15–30 min mein credits add ✅\n\n"
         . "Payment ki lekin credits nahi aaye?\n"
         . "Screenshot is chat mein bhejein 📸\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyCustomerId(): string {
    return "🆔 *Customer ID Guide*\n\n"
         . "*Step 1:* Dashboard login karein:\n"
         . "👉 ai-workflows.cloud/dashboard.html\n\n"
         . "*Step 2:* \"My Customers\" section\n\n"
         . "*Step 3:* \"Add New Customer\" → form fill → Save\n\n"
         . "*Step 4:* ID generate hogi: *CUS-XXXXXXXXXX*\n\n"
         . "*Step 5:* Ye ID extension mein paste karein\n\n"
         . "✅ Ek baar add karo — baad mein same ID use karo!\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyQuote(): string {
    return "💼 *Project Quote / Consultation*\n\n"
         . "Aapka project discuss karne ke liye main ready hoon!\n\n"
         . "*Process:*\n"
         . "1️⃣ Requirements discuss (call/WhatsApp)\n"
         . "2️⃣ Detailed proposal + timeline + pricing\n"
         . "3️⃣ Advance payment (30–50%)\n"
         . "4️⃣ Development + review\n"
         . "5️⃣ Final delivery + support\n\n"
         . "📞 *Direct call/WhatsApp:*\n"
         . "*+91 92969 14511*\n\n"
         . "🌐 More info: ai-workflows.cloud\n\n"
         . "Apna project briefly describe karein — main same din reply karunga! 🙏\n\n"
         . "─────────────────\n"
         . "🔄 Main menu: *menu* likhein";
}

function replyThanks(): string {
    return "Bahut shukriya! 🙏 Khushi hui ki help kar paya.\n\n"
         . "Koi bhi kaam ho — website, app, automation — kabhi bhi contact karein!\n\n"
         . "🌐 ai-workflows.cloud\n"
         . "📞 +91 92969 14511\n\n"
         . "— *Er. Ashok Kumar | AI Workflows* 😊";
}

// ── API HELPERS ───────────────────────────────────────────────────────────────
function sendTextMessage(string $to, string $message): void {
    callApi([
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'text',
        'text'              => ['preview_url' => false, 'body' => $message]
    ]);
}

function markRead(string $messageId): void {
    callApi([
        'messaging_product' => 'whatsapp',
        'status'            => 'read',
        'message_id'        => $messageId
    ]);
}

function callApi(array $payload): void {
    $ch = curl_init(WA_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_TOKEN,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
