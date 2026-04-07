<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

set_time_limit(30);

$input = json_decode(file_get_contents('php://input'), true);
$pdfBase64 = $input['pdf'] ?? '';
$maxPages  = min((int)($input['maxPages'] ?? 3), 5);

if (!$pdfBase64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing pdf data']);
    exit;
}

if (preg_match('/^data:([^;,]+)((?:;[^,]*)?),(.+)$/is', $pdfBase64, $m)) {
    $mime = strtolower(trim($m[1]));
    $meta = strtolower($m[2] ?? '');
    if ($mime !== 'application/pdf') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Uploaded file is not a PDF']);
        exit;
    }
    if (stripos($meta, ';base64') === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PDF payload must be base64 encoded']);
        exit;
    }
    $pdfBase64 = $m[3] ?? '';
}

$raw = preg_replace('/\s+/', '', (string)$pdfBase64);
$pdfBytes = base64_decode($raw, true);
if ($pdfBytes === false || $pdfBytes === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid PDF base64 payload']);
    exit;
}

$tmpBase = tempnam(sys_get_temp_dir(), 'scanpdf_');
if ($tmpBase === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not create temporary PDF file']);
    exit;
}

$tmpPdf = $tmpBase . '.pdf';
@unlink($tmpBase);
if (file_put_contents($tmpPdf, $pdfBytes) === false) {
    @unlink($tmpPdf);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not write temporary PDF file']);
    exit;
}

$python = '/usr/bin/python3';
$script = escapeshellarg(__DIR__ . '/pdf-to-images.py');
$tmpPdfArg = escapeshellarg($tmpPdf);
$pypath = __DIR__ . '/pylibs';
$ldpath = __DIR__ . '/pylibs/pymupdf';

$cmd = 'PYTHONPATH=' . escapeshellarg($pypath)
    . ' LD_LIBRARY_PATH=' . escapeshellarg($ldpath)
    . ' ' . escapeshellarg($python)
    . ' ' . $script
    . ' ' . $tmpPdfArg
    . ' ' . $maxPages
    . ' 2>&1';
$output = shell_exec($cmd);
@unlink($tmpPdf);

$result = json_decode($output, true);
if (!$result) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PDF conversion failed', 'raw' => substr($output, 0, 500)]);
    exit;
}

echo json_encode($result);
