<?php
@ini_set('post_max_size', '25M');
@ini_set('upload_max_filesize', '25M');
set_time_limit(120);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

set_exception_handler(function($e){
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'error' => 'Scan server exception: ' . $e->getMessage(),
    ]);
    exit;
});

// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

require_once __DIR__ . '/openai-cost-helper.php';
require_once __DIR__ . '/google-vision-helper.php';

$apiKey = getenv('OPENAI_API_KEY');
$openAiTextModel = getenv('OPENAI_TEXT_MODEL') ?: 'gpt-4o-mini';
$visionModel = getenv('OPENAI_VISION_MODEL') ?: 'gpt-4o';
$fallbackModel = getenv('OPENAI_FALLBACK_MODEL') ?: 'gpt-4o-mini';
$openAiEndpoint = getenv('OPENAI_API_ENDPOINT') ?: 'https://api.openai.com/v1/chat/completions';
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'OpenAI API key not configured']);
    exit;
}

function isDataUrlImage($dataUrl) {
    return strpos($dataUrl, 'data:image/') === 0;
}

function parseDataUrl($value) {
    if (!is_string($value)) return null;
    if (!preg_match('/^data:([^;,]+)((?:;[^,]*)?),(.+)$/is', $value, $m)) {
        return null;
    }

    return [
        'mime' => strtolower(trim($m[1])),
        'meta' => strtolower($m[2] ?? ''),
        'payload' => $m[3] ?? '',
        'isBase64' => stripos($m[2] ?? '', ';base64') !== false,
    ];
}

function decodeBase64Payload($payload) {
    if (!is_string($payload)) return null;
    $normalized = preg_replace('/\s+/', '', $payload);
    if ($normalized === '') return null;
    $decoded = base64_decode($normalized, true);
    return ($decoded === false || $decoded === '') ? null : $decoded;
}

function decodeIncomingPdfPayload($value) {
    $dataUrl = parseDataUrl($value);
    if ($dataUrl !== null) {
        if ($dataUrl['mime'] !== 'application/pdf') {
            return [null, 'Uploaded file is not a PDF'];
        }
        if (!$dataUrl['isBase64']) {
            return [null, 'PDF upload must be base64 encoded'];
        }
        $decoded = decodeBase64Payload($dataUrl['payload']);
        return $decoded !== null
            ? [$decoded, null]
            : [null, 'Invalid PDF base64 payload'];
    }

    $decoded = decodeBase64Payload($value);
    return $decoded !== null
        ? [$decoded, null]
        : [null, 'Invalid PDF payload'];
}

function runPdfToImages($pdfBytes, $maxPages = 3) {
    $script = __DIR__ . '/pdf-to-images.py';
    $python = '/usr/bin/python3';
    $pylibs = __DIR__ . '/pylibs/pymupdf';
    $pypath = __DIR__ . '/pylibs';

    if (!file_exists($script)) {
        throw new Exception('PDF conversion script not found');
    }
    if (!is_string($pdfBytes) || $pdfBytes === '') {
        throw new Exception('Decoded PDF content is empty');
    }

    $tmpBase = tempnam(sys_get_temp_dir(), 'scanpdf_');
    if ($tmpBase === false) {
        throw new Exception('Could not create temporary PDF file');
    }

    $tmpPdf = $tmpBase . '.pdf';
    @unlink($tmpBase);

    if (file_put_contents($tmpPdf, $pdfBytes) === false) {
        @unlink($tmpPdf);
        throw new Exception('Could not write temporary PDF file');
    }

    $cmd =
        'LD_LIBRARY_PATH=' . escapeshellarg($pylibs)
        . ' PYTHONPATH=' . escapeshellarg($pypath)
        . ' ' . escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($tmpPdf)
        . ' ' . max(1, min((int)$maxPages, 5))
        . ' 2>&1';

    $output = shell_exec($cmd);
    @unlink($tmpPdf);

    $result = json_decode((string)$output, true);
    if (!is_array($result) || !($result['success'] ?? false) || empty($result['pages'])) {
        $error = is_array($result)
            ? ($result['error'] ?? 'PDF conversion failed')
            : 'PDF conversion failed';
        if (!is_array($result) && is_string($output) && trim($output) !== '') {
            $error .= ': ' . substr(trim($output), 0, 180);
        }
        throw new Exception($error);
    }

    return $result;
}

function normalizeDateValue($raw) {
    $raw = trim($raw);
    if ($raw === '') return null;

    $raw = str_replace(['.', '-', ','], ['/', '/', ' '], $raw);
    $raw = preg_replace('/\s+/', ' ', $raw);

    $formats = ['d/m/Y', 'd/m/y', 'Y/m/d', 'd M Y', 'd F Y', 'j M Y', 'j F Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt instanceof DateTime) {
            return $dt->format('d/m/Y');
        }
    }

    $ts = strtotime($raw);
    if ($ts !== false) {
        return date('d/m/Y', $ts);
    }
    return null;
}

function titleCaseLoose($s) {
    $s = trim($s);
    if ($s === '') return '';
    $s = preg_replace('/\s+/', ' ', strtolower($s));
    return ucwords($s);
}

function normalizeNumericString($raw, $allowDecimal = false) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;

    $normalized = preg_replace('/[,%\x{20B9}\s]/u', '', $raw);
    $normalized = preg_replace('/[^0-9.\-]/', '', $normalized);
    if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === '-.') {
        return null;
    }

    if (!$allowDecimal) {
        $normalized = preg_replace('/\D+/', '', $normalized);
        return $normalized !== '' ? $normalized : null;
    }

    if (substr_count($normalized, '.') > 1) {
        $firstDot = strpos($normalized, '.');
        $normalized = substr($normalized, 0, $firstDot + 1) . str_replace('.', '', substr($normalized, $firstDot + 1));
    }

    return preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) ? $normalized : null;
}

function normalizeLooseUpperAlnum($raw) {
    $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$raw));
    return $normalized !== '' ? $normalized : null;
}

function extractImagePayloadForOcr($dataUrl) {
    $parsed = parseDataUrl($dataUrl);
    if ($parsed === null) {
        return [null, null, 'Invalid image payload'];
    }
    if (strpos($parsed['mime'], 'image/') !== 0) {
        return [null, null, 'OCR expects an image payload'];
    }
    if (!$parsed['isBase64']) {
        return [null, null, 'Image payload must be base64 encoded'];
    }
    if (trim((string)$parsed['payload']) === '') {
        return [null, null, 'Image payload is empty'];
    }
    return [$parsed['payload'], $parsed['mime'], null];
}

function truncateOcrText(string $text, int $limit = 18000): string {
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
}

function buildStructuredProfile(array $fields, ?string $detectedType, string $docType): array {
    $addressParts = array_values(array_filter([
        $fields['permAddr1'] ?? null,
        $fields['permAddr2'] ?? null,
        $fields['permPO'] ?? null,
        $fields['permPS'] ?? null,
        $fields['permDistrict'] ?? null,
        $fields['permState'] ?? null,
        $fields['permPin'] ?? null,
    ], static fn($value) => is_scalar($value) && trim((string)$value) !== ''));

    $idMap = [
        'aadhaarNumber' => 'aadhaar',
        'panNumber' => 'pan',
        'passportNumber' => 'passport',
        'voterId' => 'voter_id',
        'dlNumber' => 'driving_licence',
        'rationCardNumber' => 'ration_card',
        'bankAcc' => 'bank_account',
    ];
    $idType = null;
    $idNumber = null;
    foreach ($idMap as $fieldKey => $type) {
        $value = trim((string)($fields[$fieldKey] ?? ''));
        if ($value !== '') {
            $idType = $type;
            $idNumber = $value;
            break;
        }
    }

    $profile = [
        'document_type' => $detectedType ?: $docType,
        'name' => $fields['fullName'] ?? null,
        'dob' => $fields['dob'] ?? null,
        'gender' => $fields['gender'] ?? null,
        'mobile' => $fields['mobileNumber'] ?? null,
        'email' => $fields['emailId'] ?? null,
        'address' => !empty($addressParts) ? implode(', ', $addressParts) : null,
        'address_parts' => !empty($addressParts) ? $addressParts : null,
        'id_type' => $idType,
        'id_number' => $idNumber,
        'ids' => array_filter([
            'aadhaar' => $fields['aadhaarNumber'] ?? null,
            'pan' => $fields['panNumber'] ?? null,
            'passport' => $fields['passportNumber'] ?? null,
            'voter_id' => $fields['voterId'] ?? null,
            'driving_licence' => $fields['dlNumber'] ?? null,
        ], static fn($value) => is_scalar($value) && trim((string)$value) !== ''),
    ];

    return array_filter($profile, static fn($value) => $value !== null && $value !== '' && $value !== []);
}

function preprocessImageDataUrl($dataUrl) {
    if (!isDataUrlImage($dataUrl) || !function_exists('imagecreatefromstring')) {
        return $dataUrl;
    }

    if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/', $dataUrl, $m)) {
        return $dataUrl;
    }

    $mime = strtolower($m[1]);
    $raw  = base64_decode($m[2], true);
    if ($raw === false) return $dataUrl;

    $img = @imagecreatefromstring($raw);
    if (!$img) return $dataUrl;

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 1 || $h < 1) {
        imagedestroy($img);
        return $dataUrl;
    }

    // Upscale tiny images for better OCR quality.
    $minSide = min($w, $h);
    if ($minSide < 900) {
        $scale = 900 / max(1, $minSide);
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);
        $tmp = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($tmp, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $tmp;
        $w = $nw; $h = $nh;
    }

    // Downscale overly large images to reduce token + latency.
    $maxSide = max($w, $h);
    if ($maxSide > 2200) {
        $scale = 2200 / $maxSide;
        $nw = (int)round($w * $scale);
        $nh = (int)round($h * $scale);
        $tmp = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($tmp, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $tmp;
    }

    // Light contrast boost + sharpen for OCR legibility.
    @imagefilter($img, IMG_FILTER_BRIGHTNESS, 5);
    @imagefilter($img, IMG_FILTER_CONTRAST, -12);
    @imagefilter($img, IMG_FILTER_SMOOTH, -3);

    ob_start();
    imagejpeg($img, null, 90);
    $jpegData = ob_get_clean();
    imagedestroy($img);

    if (!$jpegData) return $dataUrl;
    return 'data:image/jpeg;base64,' . base64_encode($jpegData);
}

function normalizeAndValidateFields($fields) {
    $normalized = [];
    $meta = [];
    $integerNumberKeys = [
        'annualIncome', 'basicSalary', 'grossSalary', 'netSalary',
        'tenthTotal', 'tenthObtained', 'twelfthTotal', 'twelfthObtained',
        'familyMemberCount', 'disabilityPercent',
    ];
    $decimalNumberKeys = [
        'tenthPercent', 'twelfthPercent', 'diplomaPercent',
        'gradPercent', 'pgPercent', 'landArea', 'expYears',
    ];
    $yearKeys = [
        'tenthYear', 'twelfthYear', 'diplomaYear', 'gradYear', 'pgYear', 'salaryYear',
    ];

    foreach ($fields as $key => $val) {
        if (!is_scalar($val) || $val === null) continue;
        $v = trim((string)$val);
        if ($v === '') continue;

        $ok = true;
        switch ($key) {
            case 'fullName':
            case 'fatherName':
            case 'motherName':
            case 'husbandName':
            case 'spouseName':
                $v = titleCaseLoose($v);
                break;

            case 'dob':
            case 'casteIssueDate':
            case 'domicileIssueDate':
            case 'incomeIssueDate':
            case 'ewsIssueDate':
            case 'gradIssueDate':
            case 'pgIssueDate':
            case 'disabilityIssueDate':
            case 'expFrom':
            case 'expTo':
            case 'marriageDate':
                $parsed = normalizeDateValue($v);
                if ($parsed === null) $ok = false;
                else $v = $parsed;
                break;

            case 'aadhaarNumber':
                $v = preg_replace('/\D+/', '', $v);
                if (!preg_match('/^\d{12}$/', $v)) $ok = false;
                break;

            case 'mobileNumber':
            case 'altMobile':
                $v = preg_replace('/\D+/', '', $v);
                if (strlen($v) === 12 && strpos($v, '91') === 0) $v = substr($v, 2);
                if (!preg_match('/^[6-9]\d{9}$/', $v)) $ok = false;
                break;

            case 'permPin':
            case 'corrPin':
                $v = preg_replace('/\D+/', '', $v);
                if (!preg_match('/^\d{6}$/', $v)) $ok = false;
                break;

            case 'bankAcc':
                $v = preg_replace('/\D+/', '', $v);
                if (strlen($v) < 6) $ok = false;
                break;

            case 'panNumber':
                $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));
                if (!preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $v)) $ok = false;
                break;

            case 'bankIFSC':
                $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));
                if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $v)) $ok = false;
                break;

            case 'emailId':
                $v = strtolower($v);
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $ok = false;
                break;

            case 'gender':
                $u = strtoupper($v);
                if (in_array($u, ['M', 'MALE'])) $v = 'Male';
                else if (in_array($u, ['F', 'FEMALE'])) $v = 'Female';
                else if (in_array($u, ['T', 'TG', 'TRANSGENDER', 'THIRD GENDER'])) $v = 'Transgender';
                else $ok = false;
                break;

            case 'category':
                $u = strtoupper($v);
                if (in_array($u, ['GENERAL', 'UNRESERVED', 'UR'])) $v = 'UR';
                else if (in_array($u, ['SC', 'SCHEDULED CASTE'])) $v = 'SC';
                else if (in_array($u, ['ST', 'SCHEDULED TRIBE'])) $v = 'ST';
                else if (in_array($u, ['EWS'])) $v = 'EWS';
                else if (in_array($u, ['OBC', 'OBC-NCL', 'OBC NCL'])) $v = 'OBC-NCL';
                else if (in_array($u, ['OBC-CL', 'OBC CL'])) $v = 'OBC-CL';
                else $ok = false;
                break;

            case 'passportNumber':
            case 'voterId':
            case 'dlNumber':
            case 'rationCardNumber':
                $v = normalizeLooseUpperAlnum($v);
                if ($v === null) $ok = false;
                break;

            case 'isPwd':
                $u = strtoupper($v);
                if (in_array($u, ['YES', 'Y', 'TRUE'])) $v = 'Yes';
                else if (in_array($u, ['NO', 'N', 'FALSE'])) $v = 'No';
                else $ok = false;
                break;

            case 'rationCardType':
                $u = strtoupper(preg_replace('/[^A-Za-z]/', '', $v));
                if (in_array($u, ['APL', 'BPL', 'AAY', 'PHH', 'NPHH'])) $v = $u;
                else $ok = false;
                break;

            default:
                if (in_array($key, $integerNumberKeys, true)) {
                    $parsed = normalizeNumericString($v, false);
                    if ($parsed === null) $ok = false;
                    else $v = $parsed;
                    break;
                }

                if (in_array($key, $decimalNumberKeys, true)) {
                    $parsed = normalizeNumericString($v, true);
                    if ($parsed === null) $ok = false;
                    else $v = $parsed;
                    break;
                }

                if (in_array($key, $yearKeys, true)) {
                    $v = preg_replace('/\D+/', '', $v);
                    if (!preg_match('/^\d{4}$/', $v)) $ok = false;
                    break;
                }

                // Keep value as-is for non-strict keys.
                break;
        }

        if ($ok) {
            $normalized[$key] = $v;
            $meta[$key] = ['valid' => true];
        } else {
            $meta[$key] = ['valid' => false, 'dropped' => true];
        }
    }

    return [$normalized, $meta];
}

function countVisibleFieldValues($fields, $allowedKeys) {
    $count = 0;
    foreach ($allowedKeys as $key) {
        if (!array_key_exists($key, $fields)) continue;
        $value = $fields[$key];
        if ($value === null) continue;
        if (!is_scalar($value)) continue;
        if (trim((string)$value) === '') continue;
        $count++;
    }
    return $count;
}

function buildJsonSchema($fieldKeys, $includeDetectedType) {
    $properties = [];
    foreach ($fieldKeys as $k) {
        $properties[$k] = ['type' => ['string', 'null']];
    }
    if ($includeDetectedType) {
        $properties['_detectedType'] = ['type' => ['string', 'null']];
    }

    return [
        'name' => 'doc_extract',
        'schema' => [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ],
        'strict' => true,
    ];
}

function callStructuredTextExtract($apiKey, $apiEndpoint, $systemPrompt, $ocrText, $schema, $primaryModel, $fallbackModel, $maxTokens = 1400) {
    $attempts = [
        [
            'model' => $primaryModel,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $schema,
            ],
        ],
        [
            'model' => $primaryModel,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_object',
            ],
        ],
        [
            'model' => $primaryModel,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
        ],
        [
            'model' => $fallbackModel,
            'max_tokens' => min($maxTokens, 1200),
            'temperature' => 0,
        ],
    ];

    $lastError = 'OpenAI cleanup error';
    $userPrompt =
        "Use only the OCR text below to extract the requested JSON fields.\n"
        . "If a value is missing, unclear, or contradicted, return null.\n"
        . "Do not invent data that is not supported by the OCR text.\n\n"
        . "OCR TEXT:\n"
        . truncateOcrText($ocrText);

    foreach ($attempts as $basePayload) {
        $payload = $basePayload;
        $payload['messages'] = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $ch = curl_init($apiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 90,
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $lastError = 'Cleanup request failed: ' . $curlErr;
            continue;
        }

        if ($httpCode !== 200) {
            $errData = json_decode((string)$res, true);
            $lastError = $errData['error']['message'] ?? 'OpenAI cleanup error';
            continue;
        }

        $data = json_decode((string)$res, true);
        $content = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            return [
                'fields' => [],
                'metrics' => openaiBuildUsageMeta((string)$payload['model'], $data),
            ];
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $decoded = json_decode($m[0], true);
        }

        if (is_array($decoded)) {
            return [
                'fields' => $decoded,
                'metrics' => openaiBuildUsageMeta((string)$payload['model'], $data),
            ];
        }

        $lastError = 'Could not parse cleanup JSON output';
    }

    throw new Exception($lastError);
}

function callVisionExtract($apiKey, $apiEndpoint, $imageDataUrl, $prompt, $schema, $primaryModel, $fallbackModel, $maxTokens = 1400) {
    $attempts = [
        [
            'model' => $primaryModel,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $schema,
            ],
        ],
        [
            // Fallback for environments/models where json_schema is rejected
            'model' => $primaryModel,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
            'response_format' => [
                'type' => 'json_object',
            ],
        ],
        [
            // Fallback for gateways that reject response_format for vision calls
            'model' => $primaryModel,
            'max_tokens' => $maxTokens,
            'temperature' => 0,
        ],
        [
            // Broad compatibility fallback
            'model' => $fallbackModel,
            'max_tokens' => min($maxTokens, 1200),
            'temperature' => 0,
        ],
    ];

    $lastError = 'OpenAI API error';

    foreach ($attempts as $basePayload) {
        $payload = $basePayload;
        $payload['messages'] = [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl, 'detail' => 'high']],
            ],
        ]];

        $ch = curl_init($apiEndpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 90,
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $lastError = 'Request failed: ' . $curlErr;
            continue;
        }

        if ($httpCode !== 200) {
            $errData = json_decode($res, true);
            $lastError = $errData['error']['message'] ?? 'OpenAI API error';
            continue;
        }

        $data = json_decode($res, true);
        $content = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($content === '') {
            return [
                'fields' => [],
                'metrics' => openaiBuildUsageMeta((string)$payload['model'], $data),
            ];
        }

        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return [
                'fields' => $decoded,
                'metrics' => openaiBuildUsageMeta((string)$payload['model'], $data),
            ];
        }

        // Last-chance salvage: extract first JSON object from mixed text response
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $decoded2 = json_decode($m[0], true);
            if (is_array($decoded2)) {
                return [
                    'fields' => $decoded2,
                    'metrics' => openaiBuildUsageMeta((string)$payload['model'], $data),
                ];
            }
        }

        $lastError = 'Could not parse model JSON output';
    }

    throw new Exception($lastError);
}

$input   = json_decode(file_get_contents('php://input'), true);
$image   = $input['image']   ?? '';   // base64 data URL: "data:image/jpeg;base64,..."
$docType = $input['docType'] ?? 'other';
$isPdf   = !empty($input['isPdf']);

if (!$image) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing image']);
    exit;
}

// Convert PDF to JPEG image server-side using PyMuPDF
$images = [];
if ($isPdf) {
    [$pdfBytes, $pdfDecodeError] = decodeIncomingPdfPayload($image);
    if ($pdfBytes === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $pdfDecodeError ?: 'Invalid PDF upload']);
        exit;
    }

    try {
        $result = runPdfToImages($pdfBytes, 3);
        foreach ($result['pages'] as $p) {
            if (!empty($p['base64']) && is_string($p['base64'])) {
                $images[] = $p['base64'];
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if (empty($images)) {
    $images[] = $image;
}

// ── FIELD DEFINITIONS PER DOC TYPE ───────────────────────────────────────────

$fieldDefs = [
    'aadhaar' => [
        'fields' => [
            'fullName'      => 'Full name as printed on card',
            'fatherName'    => "Father's name or Husband's name (if printed as S/O, D/O, W/O, C/O)",
            'dob'           => 'Date of birth in DD/MM/YYYY format',
            'gender'        => 'Gender: Male or Female or Transgender',
            'aadhaarNumber' => '12-digit Aadhaar number (digits only, no spaces)',
            'mobileNumber'  => 'Mobile number if visible (10 digits)',
            'permAddr1'     => 'Address line 1 (house/building/street)',
            'permAddr2'     => 'Address line 2 (locality/village/area)',
            'permPO'        => 'Post Office name',
            'permPS'        => 'Police Station / Taluk name',
            'permDistrict'  => 'District name',
            'permState'     => 'State name',
            'permPin'       => '6-digit PIN code',
        ],
        'hint' => 'This is an Indian Aadhaar identity card. Extract all visible fields including S/O or D/O or W/O name as fatherName.'
    ],
    'pan' => [
        'fields' => [
            'fullName'   => 'Full name of the card holder',
            'fatherName' => "Father's name",
            'dob'        => 'Date of birth in DD/MM/YYYY format',
            'panNumber'  => 'PAN number (10 character alphanumeric, uppercase)',
        ],
        'hint' => 'This is an Indian PAN card. Extract all visible fields.'
    ],
    'marksheet10' => [
        'fields' => [
            'fullName'      => 'Student full name',
            'fatherName'    => "Father's name",
            'motherName'    => "Mother's name",
            'dob'           => 'Date of birth in DD/MM/YYYY format',
            'gender'        => 'Gender: Male or Female or Transgender (if visible)',
            'tenthBoard'    => 'Board name (e.g. BSEB, CBSE, ICSE, UP Board)',
            'tenthYear'     => 'Year of passing (4-digit year)',
            'tenthRoll'     => 'Roll number',
            'tenthCertNo'   => 'Certificate number / registration number',
            'tenthTotal'    => 'Total marks (maximum marks)',
            'tenthObtained' => 'Marks obtained',
            'tenthPercent'  => 'Percentage (number only, e.g. 78.40)',
            'tenthDivision' => 'Division or grade (e.g. First Division, A+)',
        ],
        'hint' => 'This is a 10th / Matriculation marksheet or certificate. Father and mother names are usually printed.'
    ],
    'marksheet12' => [
        'fields' => [
            'fullName'       => 'Student full name',
            'fatherName'     => "Father's name",
            'motherName'     => "Mother's name",
            'dob'            => 'Date of birth in DD/MM/YYYY format (if visible)',
            'twelfthBoard'   => 'Board name (e.g. BSEB, CBSE, ICSE, UP Board)',
            'twelfthYear'    => 'Year of passing (4-digit year)',
            'twelfthRoll'    => 'Roll number',
            'twelfthCertNo'  => 'Certificate number / registration number',
            'twelfthTotal'   => 'Total marks (maximum marks)',
            'twelfthObtained'=> 'Marks obtained',
            'twelfthPercent' => 'Percentage (number only)',
            'twelfthStream'  => 'Stream (Science / Arts / Commerce)',
        ],
        'hint' => 'This is a 12th / Intermediate marksheet or certificate. Father and mother names are usually printed.'
    ],
    'caste' => [
        'fields' => [
            'fullName'            => 'Full name of the applicant',
            'fatherName'          => "Father's name",
            'motherName'          => "Mother's name (if visible)",
            'dob'                 => 'Date of birth in DD/MM/YYYY format',
            'gender'              => 'Gender',
            'category'            => 'Caste category — must be one of: SC, ST, OBC-NCL, OBC-CL, EWS, UR (General)',
            'religion'            => 'Religion (if visible): Hindu, Muslim, Christian, Sikh, Buddhist, Jain, or Other',
            'casteCertNo'         => 'Certificate number',
            'casteIssueDate'      => 'Date of issue in DD/MM/YYYY format',
            'casteAuthority'      => 'Issuing authority / office name',
            'casteIssueDistrict'  => 'District of issue',
            'permAddr1'           => 'Address line 1 (if visible)',
            'permAddr2'           => 'Address line 2 / village / locality (if visible)',
            'permDistrict'        => 'District of residence',
            'permState'           => 'State',
        ],
        'hint' => 'This is an Indian Caste / OBC / SC / ST certificate issued by a government authority. For category, use exact values: SC, ST, OBC-NCL, OBC-CL, EWS, or UR (General).'
    ],
    'domicile' => [
        'fields' => [
            'fullName'           => 'Full name of the applicant',
            'fatherName'         => "Father's name",
            'motherName'         => "Mother's name (if visible)",
            'dob'                => 'Date of birth in DD/MM/YYYY format',
            'gender'             => 'Gender (if visible)',
            'domicileCertNo'     => 'Certificate number',
            'domicileIssueDate'  => 'Date of issue in DD/MM/YYYY format',
            'domicileAuthority'  => 'Issuing authority / office name',
            'permAddr1'          => 'Address line 1',
            'permAddr2'          => 'Address line 2 / village / locality',
            'permPO'             => 'Post Office (if visible)',
            'permPS'             => 'Police Station / Thana (if visible)',
            'permDistrict'       => 'District',
            'permState'          => 'State',
            'permPin'            => 'PIN code',
        ],
        'hint' => 'This is an Indian Domicile / Residence / Mool Niwas certificate.'
    ],
    'income' => [
        'fields' => [
            'fullName'       => 'Full name of the applicant',
            'fatherName'     => "Father's name",
            'motherName'     => "Mother's name (if visible)",
            'annualIncome'   => 'Annual family income (number only, no currency symbol)',
            'incomeCertNo'   => 'Certificate number',
            'incomeIssueDate'=> 'Date of issue in DD/MM/YYYY format',
            'permAddr1'      => 'Address line 1 (if visible)',
            'permAddr2'      => 'Address line 2 / village (if visible)',
            'permDistrict'   => 'District',
            'permState'      => 'State',
            'permPin'        => 'PIN code (if visible)',
        ],
        'hint' => 'This is an Indian Income certificate / Aay Praman Patra issued by a government authority.'
    ],
    'bank' => [
        'fields' => [
            'fullName'     => 'Account holder full name',
            'fatherName'   => "Father's / Guardian's name (if visible)",
            'bankAcc'      => 'Account number (digits only)',
            'bankIFSC'     => 'IFSC code (11 characters, uppercase)',
            'bankName'     => 'Bank name',
            'bankBranch'   => 'Branch name',
            'mobileNumber' => 'Mobile number (if visible, 10 digits)',
            'permAddr1'    => 'Address line 1 (if visible)',
            'permAddr2'    => 'Address line 2 (if visible)',
            'permDistrict' => 'District (if visible)',
            'permState'    => 'State (if visible)',
            'permPin'      => 'PIN code (if visible)',
        ],
        'hint' => 'This is an Indian bank passbook cover page or cancelled cheque. Extract all visible details.'
    ],
    'voterid' => [
        'fields' => [
            'fullName'     => 'Voter full name',
            'fatherName'   => "Father's / Husband's name",
            'dob'          => 'Date of birth in DD/MM/YYYY format or age',
            'gender'       => 'Gender: Male or Female or Transgender',
            'voterId'      => 'Voter ID / EPIC number (alphanumeric)',
            'permAddr1'    => 'Address line 1',
            'permAddr2'    => 'Address line 2',
            'permPO'       => 'Post Office (if visible)',
            'permPS'       => 'Police Station (if visible)',
            'permDistrict' => 'District',
            'permState'    => 'State',
            'permPin'      => 'PIN code',
        ],
        'hint' => 'This is an Indian Voter ID card (EPIC). Extract all visible fields.'
    ],
    'graduation' => [
        'fields' => [
            'fullName'     => 'Student full name',
            'fatherName'   => "Father's name (if visible)",
            'motherName'   => "Mother's name (if visible)",
            'gradDegree'   => 'Degree name (e.g. B.A., B.Sc., B.Com., B.Tech., B.Ed.)',
            'gradUniv'     => 'University name',
            'gradYear'     => 'Year of passing (4-digit)',
            'gradRoll'     => 'Roll number / enrollment number',
            'gradPercent'  => 'Percentage or CGPA (number only)',
            'gradIssueDate'=> 'Date of issue or convocation date in DD/MM/YYYY',
        ],
        'hint' => 'This is a graduation / degree marksheet or certificate. Father and mother names are often printed.'
    ],
    'diploma' => [
        'fields' => [
            'fullName'       => 'Student full name',
            'fatherName'     => "Father's name (if visible)",
            'motherName'     => "Mother's name (if visible)",
            'diplomaBoard'   => 'Board / institute name',
            'diplomaTrade'   => 'Trade or subject / branch name',
            'diplomaYear'    => 'Year of passing (4-digit)',
            'diplomaRoll'    => 'Roll number',
            'diplomaPercent' => 'Percentage (number only)',
        ],
        'hint' => 'This is a Diploma / ITI / Polytechnic marksheet or certificate.'
    ],
    'dl' => [
        'fields' => [
            'fullName'     => 'Licence holder full name',
            'fatherName'   => "Father's / Husband's name",
            'dob'          => 'Date of birth in DD/MM/YYYY format',
            'gender'       => 'Gender (if visible)',
            'dlNumber'     => 'Driving licence number',
            'permAddr1'    => 'Address line 1',
            'permAddr2'    => 'Address line 2',
            'permDistrict' => 'District',
            'permState'    => 'State',
            'permPin'      => 'PIN code (if visible)',
        ],
        'hint' => 'This is an Indian Driving Licence (DL). Extract all visible fields.'
    ],
    'ration' => [
        'fields' => [
            'fullName'          => 'Card holder full name (head of family)',
            'fatherName'        => "Father's / Husband's name (if visible)",
            'motherName'        => "Mother's name (if visible)",
            'rationCardNumber'  => 'Ration card number',
            'rationCardType'    => 'Card type (APL / BPL / AAY / PHH / NPHH)',
            'familyMemberCount' => 'Number of family members (digit)',
            'mobileNumber'      => 'Mobile number (if visible, 10 digits)',
            'permAddr1'         => 'Address line 1',
            'permAddr2'         => 'Address line 2',
            'permDistrict'      => 'District',
            'permState'         => 'State',
            'permPin'           => 'PIN code (if visible)',
        ],
        'hint' => 'This is an Indian Ration Card. Extract all visible fields.'
    ],
    'passport' => [
        'fields' => [
            'fullName'       => 'Passport holder full name',
            'fatherName'     => "Father's name",
            'motherName'     => "Mother's name (if visible)",
            'dob'            => 'Date of birth in DD/MM/YYYY',
            'gender'         => 'Gender: Male or Female',
            'passportNumber' => 'Passport number (alphanumeric, uppercase)',
            'permAddr1'      => 'Address line 1',
            'permAddr2'      => 'Address line 2',
            'permDistrict'   => 'District',
            'permState'      => 'State',
            'permPin'        => '6-digit PIN code',
            'mobileNumber'   => 'Mobile number (10 digits, if visible)',
            'emailId'        => 'Email address (if visible)',
        ],
        'hint' => 'This is an Indian Passport. Extract all visible fields from the front data page.'
    ],
    'birth' => [
        'fields' => [
            'fullName'    => 'Full name of the person as on birth certificate',
            'fatherName'  => "Father's name",
            'motherName'  => "Mother's name",
            'dob'         => 'Date of birth in DD/MM/YYYY',
            'gender'      => 'Gender: Male or Female',
            'birthCertNo' => 'Certificate / registration number',
            'birthPlace'  => 'Place of birth (hospital or village/town name)',
            'permAddr1'   => 'Address line 1 (if visible)',
            'permAddr2'   => 'Address line 2 (if visible)',
            'permDistrict'=> 'District',
            'permState'   => 'State',
        ],
        'hint' => 'This is an Indian Birth Certificate issued by municipal/gram panchayat authority.'
    ],
    'ews' => [
        'fields' => [
            'fullName'     => 'Full name of the applicant',
            'fatherName'   => "Father's name",
            'motherName'   => "Mother's name (if visible)",
            'dob'          => 'Date of birth in DD/MM/YYYY (if visible)',
            'gender'       => 'Gender (if visible)',
            'category'     => 'Category — should be EWS',
            'annualIncome' => 'Annual family income (number only)',
            'ewsCertNo'    => 'Certificate number',
            'ewsIssueDate' => 'Date of issue in DD/MM/YYYY',
            'ewsFinYear'   => 'Financial year (e.g. 2023-24)',
            'ewsAuthority' => 'Issuing authority / office name',
            'permAddr1'    => 'Address line 1 (if visible)',
            'permAddr2'    => 'Address line 2 (if visible)',
            'permDistrict' => 'District',
            'permState'    => 'State',
        ],
        'hint' => 'This is an Indian EWS (Economically Weaker Section) certificate issued by a government authority.'
    ],
    'pwdcert' => [
        'fields' => [
            'fullName'           => 'Full name of the person',
            'fatherName'         => "Father's / Guardian's name",
            'dob'                => 'Date of birth in DD/MM/YYYY',
            'gender'             => 'Gender',
            'disabilityCertNo'   => 'Certificate / UDID number',
            'disabilityType'     => 'Type of disability (e.g. Locomotor, Visual, Hearing, Speech, Intellectual)',
            'disabilityPercent'  => 'Percentage of disability (number only)',
            'disabilityIssueDate'=> 'Date of issue in DD/MM/YYYY',
            'disabilityAuthority'=> 'Issuing hospital or authority name',
            'permAddr1'          => 'Address line 1',
            'permAddr2'          => 'Address line 2',
            'permDistrict'       => 'District',
            'permState'          => 'State',
        ],
        'hint' => 'This is an Indian Disability Certificate or UDID (Unique Disability ID) card. Extract all visible fields.'
    ],
    'pg' => [
        'fields' => [
            'fullName'    => 'Student full name',
            'fatherName'  => "Father's name (if visible)",
            'motherName'  => "Mother's name (if visible)",
            'pgDegree'    => 'Post graduation degree name (e.g. M.A., M.Sc., M.Com., M.Tech., MBA, MCA)',
            'pgUniv'      => 'University name',
            'pgYear'      => 'Year of passing (4-digit)',
            'pgRoll'      => 'Roll number / enrollment number',
            'pgPercent'   => 'Percentage or CGPA (number only)',
            'pgIssueDate' => 'Issue or convocation date in DD/MM/YYYY',
        ],
        'hint' => 'This is a Post Graduation / Masters degree marksheet or certificate.'
    ],
    'experience' => [
        'fields' => [
            'fullName'      => 'Employee full name',
            'expDesignation'=> 'Designation / post held',
            'expOrg'        => 'Organisation / company name',
            'expFrom'       => 'Joining date / from date in DD/MM/YYYY',
            'expTo'         => 'Relieving date / to date in DD/MM/YYYY (if mentioned)',
            'expYears'      => 'Total experience in years (if mentioned, number only)',
            'permAddr1'     => 'Organisation address line 1 (if visible)',
            'permDistrict'  => 'District (if visible)',
            'permState'     => 'State (if visible)',
        ],
        'hint' => 'This is an Indian Experience Certificate or Service Certificate or Relieving Letter.'
    ],
    'marriage' => [
        'fields' => [
            'fullName'         => 'Name of husband / first party',
            'spouseName'       => 'Name of wife / second party',
            'fatherName'       => "Husband's father name (if visible)",
            'dob'              => 'Date of birth of applicant in DD/MM/YYYY (if visible)',
            'marriageDate'     => 'Date of marriage in DD/MM/YYYY',
            'marriageCertNo'   => 'Certificate / registration number',
            'marriageAuthority'=> 'Issuing authority / registrar office',
            'marriageDistrict' => 'District of registration',
            'permState'        => 'State',
        ],
        'hint' => 'This is an Indian Marriage Certificate / Vivah Panjikaran Praman Patra.'
    ],
    'salary' => [
        'fields' => [
            'fullName'    => 'Employee full name',
            'employeeId'  => 'Employee ID / staff number',
            'designation' => 'Designation / post',
            'orgName'     => 'Organisation / department name',
            'basicSalary' => 'Basic salary (number only)',
            'grossSalary' => 'Gross salary (number only)',
            'netSalary'   => 'Net salary / take-home (number only)',
            'salaryMonth' => 'Month of salary (e.g. March)',
            'salaryYear'  => 'Year of salary (4-digit)',
            'panNumber'   => 'PAN number (if visible)',
            'bankAcc'     => 'Bank account number (if visible)',
            'bankName'    => 'Bank name (if visible)',
            'bankIFSC'    => 'IFSC code (if visible)',
        ],
        'hint' => 'This is an Indian Salary Slip / Pay Slip. Extract all visible financial and employee details.'
    ],
    'all_in_one' => [
        'fields' => [
            // ── PERSONAL ──
            'fullName'            => 'Full name as printed on the document (use Title Case)',
            'fatherName'          => "Father's name — the person's name that follows S/O, D/O, W/O, C/O, Son of, Daughter of, or Wife of",
            'motherName'          => "Mother's name (if explicitly printed)",
            'husbandName'         => "Husband's name (only if W/O is used and document is clearly for a married woman — otherwise leave null)",
            'dob'                 => 'Date of birth in DD/MM/YYYY format',
            'gender'              => 'Gender: Male or Female or Transgender',
            'category'            => 'Caste/social category — use ONLY: SC, ST, OBC-NCL, OBC-CL, EWS, or UR (for General/Unreserved)',
            'religion'            => 'Religion: Hindu, Muslim, Christian, Sikh, Buddhist, Jain, or Other',
            'nationality'         => 'Nationality (typically Indian)',
            'maritalStatus'       => 'Marital status if visible: Married, Unmarried, Widow, Divorced',
            'identMark1'          => 'Identification mark 1 (mole, scar, cut) if printed on document',
            'identMark2'          => 'Identification mark 2 if a second mark is printed',
            'isPwd'               => 'Is this person a Person with Disability (PwD)? Write Yes or No — only if explicitly certified',
            'disabilityType'      => 'Type of disability (Locomotor, Visual, Hearing, Speech, Intellectual, Multiple)',
            'disabilityPercent'   => 'Disability percentage — number only, no % symbol (e.g. 40)',
            'pwdCertNo'           => 'PwD / Disability / UDID certificate number',
            'disabilityIssueDate' => 'Disability certificate date of issue in DD/MM/YYYY',
            'disabilityAuthority' => 'Disability certificate issuing hospital or authority name',
            // ── CONTACT ──
            'mobileNumber'        => 'Primary mobile number — exactly 10 digits, no country code, no spaces',
            'altMobile'           => 'Alternate / secondary mobile number (10 digits) if a second number is visible',
            'emailId'             => 'Email address',
            // ── PERMANENT ADDRESS ──
            'permAddr1'           => 'Permanent address line 1 — house number, building, street',
            'permAddr2'           => 'Permanent address line 2 — village, locality, mohalla, area',
            'permPO'              => 'Post Office name',
            'permPS'              => 'Police Station / Thana / Taluk name',
            'permDistrict'        => 'Permanent district name',
            'permState'           => 'Permanent state name',
            'permPin'             => '6-digit permanent address PIN code',
            // ── CORRESPONDENCE ADDRESS ──
            'corrAddr1'           => 'Correspondence / present address line 1 — only if this is a DIFFERENT address from permanent',
            'corrAddr2'           => 'Correspondence address line 2',
            'corrPO'              => 'Correspondence Post Office',
            'corrPS'              => 'Correspondence Police Station',
            'corrDistrict'        => 'Correspondence district',
            'corrState'           => 'Correspondence state',
            'corrPin'             => 'Correspondence PIN code',
            // ── IDENTITY DOCUMENTS ──
            'aadhaarNumber'       => 'Aadhaar number — remove ALL spaces, return exactly 12 digits (e.g. "1234 5678 9012" → "123456789012")',
            'panNumber'           => 'PAN number — always 10-character UPPERCASE (e.g. ABCDE1234F)',
            'voterId'             => 'Voter ID / EPIC number (alphanumeric, uppercase)',
            'passportNumber'      => 'Passport number (alphanumeric, uppercase)',
            'dlNumber'            => 'Driving licence number',
            // ── 10TH EDUCATION ──
            'tenthBoard'          => '10th / Matriculation board name (e.g. BSEB, CBSE, ICSE, UP Board)',
            'tenthYear'           => '10th year of passing (4-digit)',
            'tenthRoll'           => '10th roll number',
            'tenthCertNo'         => '10th certificate or registration number',
            'tenthTotal'          => '10th total / maximum marks (number only)',
            'tenthObtained'       => '10th marks obtained (number only)',
            'tenthPercent'        => '10th percentage (number only, e.g. 78.40)',
            'tenthDivision'       => '10th division or grade (e.g. First Division, A+)',
            // ── 12TH EDUCATION ──
            'twelfthBoard'        => '12th / Intermediate board name',
            'twelfthYear'         => '12th year of passing (4-digit)',
            'twelfthRoll'         => '12th roll number',
            'twelfthCertNo'       => '12th certificate or registration number',
            'twelfthTotal'        => '12th total marks (number only)',
            'twelfthObtained'     => '12th marks obtained (number only)',
            'twelfthPercent'      => '12th percentage (number only)',
            'twelfthStream'       => '12th stream (Science, Arts, Commerce)',
            // ── DIPLOMA ──
            'diplomaBoard'        => 'Diploma board or institute name',
            'diplomaTrade'        => 'Diploma trade, branch, or subject',
            'diplomaYear'         => 'Diploma year of passing (4-digit)',
            'diplomaRoll'         => 'Diploma roll number',
            'diplomaPercent'      => 'Diploma percentage (number only)',
            // ── ACADEMIC / EXAM SHEET DETAILS ──
            'enrollmentNumber'    => 'Enrollment number / enrolment number / enrollment no',
            'applicationName'     => 'Application / exam name printed on the document',
            'studentType'         => 'Student type, course mode, or class type (e.g. REGULAR / B.Sc)',
            'academicSession'     => 'Academic session or session year (e.g. 2023-2027)',
            'semester'            => 'Semester number or semester label (e.g. Semester 6)',
            'stream'              => 'Academic stream, branch, or course stream',
            'universityRollNumber'=> 'University roll number / university roll no',
            'registrationNumber'  => 'Registration number / registration no',
            'rollNumber'          => 'Roll number / roll no',
            'mjcSubject'          => 'MJC subject / Major subject',
            'micSubject'          => 'MIC subject / Minor subject',
            'mdcSubject'          => 'MDC subject',
            'aecSubject'          => 'AEC subject',
            'secSubject'          => 'SEC subject',
            'vacSubject'          => 'VAC subject',
            // ── GRADUATION ──
            'gradDegree'          => 'Graduation degree name (e.g. B.A., B.Sc., B.Com., B.Tech., B.Ed.)',
            'gradUniv'            => 'Graduation university name',
            'gradYear'            => 'Graduation year of passing (4-digit)',
            'gradRoll'            => 'Graduation roll / enrollment number',
            'gradPercent'         => 'Graduation percentage or CGPA (number only)',
            'gradIssueDate'       => 'Graduation degree issue or convocation date in DD/MM/YYYY',
            // ── POST GRADUATION ──
            'pgDegree'            => 'Post graduation degree name (e.g. M.A., M.Sc., MBA, M.Tech., MCA)',
            'pgUniv'              => 'Post graduation university name',
            'pgYear'              => 'Post graduation year of passing (4-digit)',
            'pgRoll'              => 'Post graduation roll / enrollment number',
            'pgPercent'           => 'Post graduation percentage or CGPA (number only)',
            'pgIssueDate'         => 'Post graduation degree issue date in DD/MM/YYYY',
            // ── CASTE CERTIFICATE ──
            'casteCertNo'         => 'Caste / OBC / SC / ST certificate number',
            'casteIssueDate'      => 'Caste certificate date of issue in DD/MM/YYYY',
            'casteAuthority'      => 'Caste certificate issuing authority / office name',
            'casteIssueDistrict'  => 'District from which caste certificate was issued',
            // ── DOMICILE CERTIFICATE ──
            'domicileCertNo'      => 'Domicile / residence / Mool Niwas certificate number',
            'domicileIssueDate'   => 'Domicile certificate date of issue in DD/MM/YYYY',
            'domicileAuthority'   => 'Domicile certificate issuing authority / office name',
            // ── INCOME / EWS CERTIFICATE ──
            'incomeCertNo'        => 'Income certificate number',
            'incomeIssueDate'     => 'Income certificate date of issue in DD/MM/YYYY',
            'annualIncome'        => 'Annual family income — number only, no ₹ or commas (e.g. "1,25,000" → "125000")',
            'ewsCertNo'           => 'EWS (Economically Weaker Section) certificate number',
            'ewsIssueDate'        => 'EWS certificate date of issue in DD/MM/YYYY',
            'ewsFinYear'          => 'EWS certificate financial year (e.g. 2023-24)',
            'ewsAuthority'        => 'EWS certificate issuing authority / office name',
            // ── BIRTH CERTIFICATE ──
            'birthCertNo'         => 'Birth certificate / registration number',
            'birthPlace'          => 'Place of birth (hospital or village/town name)',
            // ── BANK ──
            'bankAcc'             => 'Bank account number (digits only, no spaces)',
            'bankIFSC'            => 'Bank IFSC code — always 11-character UPPERCASE',
            'bankName'            => 'Bank name',
            'bankBranch'          => 'Bank branch name',
            // ── LAND / FARMER RECORDS ──
            'khasraNumber'        => 'Khasra number / land plot number (from land records or PM Kisan doc)',
            'khatauniNumber'      => 'Khatauni number / land record number',
            'landArea'            => 'Land area (number only, in acres or hectares as stated)',
            'tehsil'              => 'Tehsil / Taluka name',
            'gramSabha'           => 'Gram Sabha / village name (from land record or scheme document)',
            // ── RATION CARD ──
            'rationCardNumber'    => 'Ration card number',
            'rationCardType'      => 'Ration card type: APL, BPL, AAY, PHH, or NPHH',
            'familyMemberCount'   => 'Number of family members on ration card (digit only)',
            // ── EXPERIENCE CERTIFICATE ──
            'expDesignation'      => 'Designation or post held (from experience / service certificate)',
            'expOrg'              => 'Organisation or company name (from experience certificate)',
            'expFrom'             => 'Joining / from date in DD/MM/YYYY',
            'expTo'               => 'Relieving / to date in DD/MM/YYYY (if mentioned)',
            'expYears'            => 'Total experience in years — number only (if stated)',
            // ── MARRIAGE CERTIFICATE ──
            'spouseName'          => "Spouse's full name (wife's or husband's name from marriage certificate)",
            'marriageDate'        => 'Date of marriage in DD/MM/YYYY',
            'marriageCertNo'      => 'Marriage certificate or registration number',
            'marriageAuthority'   => 'Marriage registrar or issuing authority name',
            'marriageDistrict'    => 'District of marriage registration',
            // ── SALARY SLIP ──
            'employeeId'          => 'Employee ID or staff number (from salary slip)',
            'designation'         => 'Designation or post (from salary slip)',
            'orgName'             => 'Organisation or department name (from salary slip)',
            'basicSalary'         => 'Basic salary — number only',
            'grossSalary'         => 'Gross salary — number only',
            'netSalary'           => 'Net / take-home salary — number only',
            'salaryMonth'         => 'Salary month (e.g. March)',
            'salaryYear'          => 'Salary year (4-digit)',
            // ── DETECTED TYPE ──
            '_detectedType'       => 'Identify this document in plain English (e.g. "Aadhaar Card", "10th Marksheet BSEB", "OBC-NCL Caste Certificate Bihar", "Bank Passbook SBI", "Domicile Certificate UP")',
        ],
        'hint' => 'This is an Indian government or educational document — could be Aadhaar, PAN, Voter ID, Driving Licence, Passport, Ration Card, Caste/Domicile/Income/EWS certificate, 10th/12th/Diploma/Graduation/PG marksheet or degree, Bank Passbook/Cheque, Birth Certificate, Disability/UDID certificate, Experience/Service Certificate, Marriage Certificate, Salary Slip, PM Kisan/land record, or any other. FIRST identify the document type (_detectedType). THEN extract every field visible on the document. For any field not present on this document, set it to null. Never guess or invent data.'
    ],

    'other' => [
        'fields' => [
            'fullName'      => 'Full name',
            'fatherName'    => "Father's name",
            'motherName'    => "Mother's name",
            'dob'           => 'Date of birth in DD/MM/YYYY',
            'gender'        => 'Gender',
            'mobileNumber'  => 'Mobile number (10 digits)',
            'emailId'       => 'Email address',
            'aadhaarNumber' => 'Aadhaar number (12 digits)',
            'panNumber'     => 'PAN number',
            'voterId'       => 'Voter ID number',
            'passportNumber'=> 'Passport number',
            'dlNumber'      => 'Driving licence number',
            'permAddr1'     => 'Address line 1',
            'permAddr2'     => 'Address line 2',
            'permDistrict'  => 'District',
            'permState'     => 'State',
            'permPin'       => 'PIN code',
            'casteCertNo'   => 'Caste certificate number',
            'domicileCertNo'=> 'Domicile certificate number',
            'incomeCertNo'  => 'Income certificate number',
            'bankAcc'       => 'Bank account number',
            'bankIFSC'      => 'Bank IFSC code',
            'bankName'      => 'Bank name',
        ],
        'hint' => 'This is an Indian government document. Extract any visible fields.'
    ],
];

$def = $fieldDefs[$docType] ?? $fieldDefs['other'];

// Build field list for prompt
$fieldList = '';
foreach ($def['fields'] as $key => $desc) {
    $fieldList .= "  \"$key\": $desc\n";
}

$systemPrompt = <<<PROMPT
You are an expert OCR assistant for Indian government and educational documents.
{$def['hint']}

CRITICAL RULES — follow every one precisely:
1. Extract ONLY the fields listed below. Return null for any field not visible or not determinable.
2. DATES: Return in DD/MM/YYYY format. Convert any format (YYYY-MM-DD, "15 March 2001", etc.) to DD/MM/YYYY.
3. AADHAAR: Remove ALL spaces — return exactly 12 digits. "1234 5678 9012" → "123456789012".
4. PAN: Always uppercase 10 characters. "abcde1234f" → "ABCDE1234F".
5. IFSC: Always uppercase 11 characters.
6. NUMBERS (marks, percent, income, salary): plain number only — no ₹, %, commas, or text. "₹1,25,000" → "125000". "78.40%" → "78.40".
7. NAMES: Title Case. "RAM KUMAR SHARMA" → "Ram Kumar Sharma".
8. FATHER'S NAME: The name after S/O, D/O, W/O, C/O, Son of, Daughter of, Wife of — always map to fatherName (unless married woman W/O → use husbandName).
9. CATEGORY: Map exactly — General/Unreserved/UR → "UR", OBC/OBC-NCL → "OBC-NCL", OBC-CL → "OBC-CL", SC/Scheduled Caste → "SC", ST/Scheduled Tribe → "ST", EWS → "EWS".
10. Do NOT invent, guess, or assume any value. If a field is blurry, missing, or unclear → null.
11. Return ONLY valid JSON. No markdown fences, no explanation, no extra text.

Fields to extract:
$fieldList
PROMPT;

$verifyPrompt = <<<PROMPT
You are a strict OCR verifier for Indian documents.
Re-read the same document image and verify/correct extracted values.

Rules:
1. Return ONLY fields that are clearly visible and highly certain.
2. If uncertain, return null for that field.
3. Follow strict normalization:
   - Aadhaar: 12 digits only
   - PAN: AAAAA9999A (uppercase)
   - IFSC: 11-char uppercase
   - Dates: DD/MM/YYYY
   - Mobile: 10 digits
4. Do not guess. Precision over recall.

Fields to verify:
$fieldList
PROMPT;

$allowed = array_keys($def['fields']);
$includeDetectedType = ($docType === 'all_in_one');
$schema = buildJsonSchema($allowed, $includeDetectedType);
$googleWarnings = [];
$googleOcrMetrics = [
    'provider' => 'google_vision',
    'configured' => googleVisionIsConfigured(),
    'pagesAttempted' => count($images),
    'pagesWithText' => 0,
    'textChars' => 0,
];

if (googleVisionIsConfigured()) {
    $ocrPages = [];

    foreach ($images as $idx => $img) {
        $preparedImage = preprocessImageDataUrl($img);
        [$imageBase64, $imageMime, $imageError] = extractImagePayloadForOcr($preparedImage);
        if ($imageBase64 === null) {
            $googleWarnings[] = 'Page ' . ($idx + 1) . ': ' . $imageError;
            continue;
        }

        $ocrResult = googleVisionExtractDocumentText($imageBase64, $imageMime);
        if (!($ocrResult['success'] ?? false)) {
            $googleWarnings[] = 'Page ' . ($idx + 1) . ': ' . ($ocrResult['error'] ?? 'Google OCR failed');
            continue;
        }

        $pageText = trim((string)($ocrResult['text'] ?? ''));
        if ($pageText === '') {
            $googleWarnings[] = 'Page ' . ($idx + 1) . ': Google OCR returned no text';
            continue;
        }

        $googleOcrMetrics['pagesWithText']++;
        $ocrPages[] = "PAGE " . ($idx + 1) . "\n" . $pageText;
    }

    $combinedOcrText = trim(implode("\n\n====================\n\n", $ocrPages));
    $googleOcrMetrics['textChars'] = strlen($combinedOcrText);

    if ($combinedOcrText !== '') {
        try {
            $cleanupResult = callStructuredTextExtract(
                $apiKey,
                $openAiEndpoint,
                $systemPrompt,
                $combinedOcrText,
                $schema,
                $openAiTextModel,
                $fallbackModel,
                ($docType === 'all_in_one') ? 2200 : 1400
            );

            $cleanupFields = is_array($cleanupResult['fields'] ?? null) ? $cleanupResult['fields'] : [];
            $cleanupDetectedType = $includeDetectedType ? trim((string)($cleanupFields['_detectedType'] ?? '')) : null;
            if ($includeDetectedType) {
                unset($cleanupFields['_detectedType']);
            }

            $merged = [];
            $fieldMeta = [];
            foreach ($allowed as $key) {
                if (!array_key_exists($key, $cleanupFields)) continue;
                $value = $cleanupFields[$key];
                if (!is_scalar($value) && $value !== null) continue;
                $value = trim((string)$value);
                if ($value === '') continue;
                $merged[$key] = $value;
                $fieldMeta[$key] = [
                    'confidence' => 1,
                    'votes' => 1,
                    'total' => 1,
                    'source' => 'google_ocr_openai_cleanup',
                ];
            }

            [$normalized, $validationMeta] = normalizeAndValidateFields($merged);
            if (empty($normalized) && !empty($merged)) {
                $normalized = $merged;
                $googleWarnings[] = 'Validation dropped all cleanup fields; raw cleanup output used';
            }

            foreach ($validationMeta as $k => $vm) {
                if (!isset($fieldMeta[$k])) $fieldMeta[$k] = [];
                $fieldMeta[$k] = array_merge($fieldMeta[$k], $vm);
            }

            if (!empty($normalized)) {
                echo json_encode([
                    'success' => true,
                    'fields' => $normalized,
                    'structuredProfile' => buildStructuredProfile($normalized, $cleanupDetectedType ?: null, $docType),
                    'fieldMeta' => $fieldMeta,
                    'docType' => $docType,
                    'detectedType' => $cleanupDetectedType ?: null,
                    'pagesProcessed' => count($images),
                    'candidateFieldCount' => count($merged),
                    'mergedFieldCount' => count($merged),
                    'normalizedFieldCount' => count($normalized),
                    'metrics' => [
                        'scanAi' => openaiAggregateUsageMeta(array_filter([$cleanupResult['metrics'] ?? null])),
                        'googleOcr' => $googleOcrMetrics,
                    ],
                    'warnings' => $googleWarnings,
                ]);
                exit;
            }

            $googleWarnings[] = 'Google OCR cleanup returned no usable fields';
        } catch (Throwable $e) {
            $googleWarnings[] = 'Google OCR cleanup failed: ' . $e->getMessage();
        }
    } else {
        $googleWarnings[] = 'Google OCR returned no usable text; falling back to OpenAI vision';
    }
} else {
    $googleWarnings[] = 'Google OCR not configured; using OpenAI vision fallback';
}

$fieldCandidates = [];
$detectedTypes = [];
$errors = $googleWarnings;
$aiCallMetrics = [];

foreach ($images as $idx => $img) {
    $preparedImage = preprocessImageDataUrl($img);

    try {
        $pass1Result = callVisionExtract(
            $apiKey,
            $openAiEndpoint,
            $preparedImage,
            $systemPrompt,
            $schema,
            $visionModel,
            $fallbackModel,
            ($docType === 'all_in_one') ? 2400 : 1400
        );
        $pass1 = $pass1Result['fields'] ?? [];
        if (!empty($pass1Result['metrics'])) $aiCallMetrics[] = $pass1Result['metrics'];

        $pass2 = [];
        $pass1VisibleCount = countVisibleFieldValues($pass1, $allowed);
        $shouldVerify = !($docType === 'all_in_one' && $pass1VisibleCount >= 10);

        if ($shouldVerify) {
            $pass2Result = callVisionExtract(
                $apiKey,
                $openAiEndpoint,
                $preparedImage,
                $verifyPrompt,
                $schema,
                $visionModel,
                $fallbackModel,
                ($docType === 'all_in_one') ? 1200 : 900
            );
            $pass2 = $pass2Result['fields'] ?? [];
            if (!empty($pass2Result['metrics'])) $aiCallMetrics[] = $pass2Result['metrics'];
        } else {
            $errors[] = 'Page ' . ($idx + 1) . ': verification pass skipped for faster scan';
        }

        // Combine values from both passes, preferring consensus.
        foreach ($allowed as $key) {
            $v1 = isset($pass1[$key]) ? trim((string)$pass1[$key]) : '';
            $v2 = isset($pass2[$key]) ? trim((string)$pass2[$key]) : '';

            if ($v1 !== '') $fieldCandidates[$key][] = $v1;
            if ($v2 !== '') $fieldCandidates[$key][] = $v2;
        }

        if ($includeDetectedType) {
            $dt1 = trim((string)($pass1['_detectedType'] ?? ''));
            $dt2 = trim((string)($pass2['_detectedType'] ?? ''));
            if ($dt1 !== '') $detectedTypes[] = $dt1;
            if ($dt2 !== '') $detectedTypes[] = $dt2;
        }
    } catch (Throwable $e) {
        $errors[] = 'Page ' . ($idx + 1) . ': ' . $e->getMessage();
    }
}

if (empty($fieldCandidates) && !empty($errors)) {
    // Return JSON with success=false (not HTTP 500) so client can surface clean error text.
    echo json_encode(['success' => false, 'error' => 'All OCR extraction attempts failed', 'details' => $errors]);
    exit;
}

// Majority vote style merge across pages + passes.
$merged = [];
$fieldMeta = [];
foreach ($fieldCandidates as $key => $values) {
    if (empty($values)) continue;
    $counts = [];
    foreach ($values as $v) {
        $vv = trim((string)$v);
        if ($vv === '') continue;
        if (!isset($counts[$vv])) $counts[$vv] = 0;
        $counts[$vv]++;
    }
    if (empty($counts)) continue;

    arsort($counts);
    $bestValue = array_key_first($counts);
    $bestCount = (int)$counts[$bestValue];
    $total = array_sum($counts);
    $confidence = $total > 0 ? round($bestCount / $total, 2) : 0;

    $merged[$key] = $bestValue;
    $fieldMeta[$key] = [
        'confidence' => $confidence,
        'votes' => $bestCount,
        'total' => $total,
    ];
}

[$normalized, $validationMeta] = normalizeAndValidateFields($merged);

// Safety fallback: if strict validation drops everything, keep raw merged output
// so scanning still returns usable fields instead of "No fields extracted".
if (empty($normalized) && !empty($merged)) {
    $normalized = $merged;
    $errors[] = 'Validation dropped all fields; raw merged fallback used';
}

foreach ($validationMeta as $k => $vm) {
    if (!isset($fieldMeta[$k])) $fieldMeta[$k] = [];
    $fieldMeta[$k] = array_merge($fieldMeta[$k], $vm);
}

// Extract detectedType separately (all_in_one only), majority vote.
$detectedType = null;
if ($includeDetectedType && !empty($detectedTypes)) {
    $dtCounts = [];
    foreach ($detectedTypes as $dt) {
        $d = trim($dt);
        if ($d === '') continue;
        if (!isset($dtCounts[$d])) $dtCounts[$d] = 0;
        $dtCounts[$d]++;
    }
    if (!empty($dtCounts)) {
        arsort($dtCounts);
        $detectedType = (string)array_key_first($dtCounts);
    }
}

$scanAiMetrics = openaiAggregateUsageMeta($aiCallMetrics);

echo json_encode([
    'success' => true,
    'fields' => $normalized,
    'structuredProfile' => buildStructuredProfile($normalized, $detectedType, $docType),
    'fieldMeta' => $fieldMeta,
    'docType' => $docType,
    'detectedType' => $detectedType,
    'pagesProcessed' => count($images),
    'candidateFieldCount' => count($fieldCandidates),
    'mergedFieldCount' => count($merged),
    'normalizedFieldCount' => count($normalized),
    'metrics' => [
        'scanAi' => $scanAiMetrics,
        'googleOcr' => $googleOcrMetrics,
    ],
    'warnings' => $errors,
]);
?>
