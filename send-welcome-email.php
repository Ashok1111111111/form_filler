<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$data  = json_decode(file_get_contents('php://input'), true);
$name  = trim($data['name']  ?? '');
$email = trim($data['email'] ?? '');

if (!$email) {
    echo json_encode(['success' => false, 'error' => 'Missing email']);
    exit;
}

$result = sendWelcomeMail($name ?: 'User', $email);
echo json_encode($result);

function sendWelcomeMail(string $name, string $toEmail): array {
    $smtpHost = 'smtp.gmail.com';
    $smtpPort = 587;
    $smtpUser = 'ashokkumar1112.ch@gmail.com';
    $smtpPass = 'vljm ksgz fxwq bdjx';
    $fromName = 'AI Form Filler';

    $subject  = "🎉 Welcome to AI Form Filler — ₹100 Free Wallet Balance!";
    $body     = buildEmailBody($name);

    $headers  = [
        "From: {$fromName} <{$smtpUser}>",
        "To: {$name} <{$toEmail}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "X-Mailer: PHP/AI-Form-Filler",
    ];

    // Connect to Gmail SMTP via TLS
    $socket = @stream_socket_client(
        "tcp://{$smtpHost}:{$smtpPort}", $errno, $errstr, 15
    );
    if (!$socket) return ['success' => false, 'error' => "Connect failed: $errstr"];

    stream_set_timeout($socket, 15);

    $read = function() use ($socket) {
        $resp = '';
        while ($line = fgets($socket, 512)) {
            $resp .= $line;
            if ($line[3] === ' ') break; // end of multi-line response
        }
        return $resp;
    };

    $send = function(string $cmd) use ($socket, $read) {
        fwrite($socket, $cmd . "\r\n");
        return $read();
    };

    $read(); // server greeting
    $send("EHLO formfiller.ai-workflows.cloud");

    // Start TLS
    $send("STARTTLS");
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $send("EHLO formfiller.ai-workflows.cloud");

    // Auth
    $send("AUTH LOGIN");
    $send(base64_encode($smtpUser));
    $authResp = $send(base64_encode($smtpPass));
    if (strpos($authResp, '235') === false) {
        fclose($socket);
        return ['success' => false, 'error' => 'Auth failed: ' . trim($authResp)];
    }

    // Envelope
    $send("MAIL FROM:<{$smtpUser}>");
    $send("RCPT TO:<{$toEmail}>");
    $send("DATA");

    // Message
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    $dataResp = $send($message);

    $send("QUIT");
    fclose($socket);

    if (strpos($dataResp, '250') !== false) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Send failed: ' . trim($dataResp)];
}

function buildEmailBody(string $name): string {
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4fb;font-family:'Segoe UI',sans-serif;">
  <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#5b4ff5,#7c3aed);padding:36px 40px;text-align:center;">
      <div style="font-size:2rem;font-weight:800;color:#fff;letter-spacing:-1px;">AI Form Filler</div>
      <div style="color:rgba(255,255,255,.75);font-size:.95rem;margin-top:4px;">Smart Form Filling for India</div>
    </div>
    <div style="padding:36px 40px;">
      <h2 style="margin:0 0 8px;color:#1e1b4b;font-size:1.4rem;">Welcome, {$name}! 🎉</h2>
      <p style="color:#555;line-height:1.7;margin:0 0 24px;">Your account is ready. We've added <strong style="color:#5b4ff5;">₹100 free wallet balance</strong> to get you started!</p>

      <div style="background:linear-gradient(135deg,#5b4ff5,#7c3aed);border-radius:12px;padding:20px 24px;text-align:center;margin-bottom:28px;">
        <div style="color:rgba(255,255,255,.8);font-size:.85rem;margin-bottom:4px;">YOUR WALLET BALANCE</div>
        <div style="color:#fff;font-size:2.2rem;font-weight:800;">₹100.00</div>
        <div style="color:rgba(255,255,255,.7);font-size:.8rem;margin-top:4px;">≈ 5,000 form fields filled free</div>
      </div>

      <h3 style="color:#1e1b4b;margin:0 0 14px;font-size:1rem;">How to get started:</h3>
      <ol style="color:#555;line-height:2;margin:0 0 28px;padding-left:20px;">
        <li>Go to <strong>My Customers</strong> → Add your customer details</li>
        <li>Install the <strong>Chrome Extension</strong></li>
        <li>Open any government form → Click <strong>Fill Form</strong></li>
      </ol>

      <div style="text-align:center;margin-bottom:24px;">
        <a href="https://formfiller.ai-workflows.cloud/dashboard.html"
           style="display:inline-block;background:linear-gradient(135deg,#5b4ff5,#7c3aed);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-weight:700;font-size:1rem;">
          Go to Dashboard →
        </a>
      </div>

      <div style="border-top:1px solid #f0f0f8;padding-top:20px;text-align:center;">
        <p style="color:#999;font-size:.8rem;margin:0;">Need help? WhatsApp us at
          <a href="https://wa.me/917999614511" style="color:#5b4ff5;">+91 7999614511</a>
        </p>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
}
?>
