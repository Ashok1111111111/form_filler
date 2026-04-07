<?php

use Google\Auth\Credentials\ServiceAccountCredentials;

$googleAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($googleAutoload)) {
    require_once $googleAutoload;
}

function googleVisionLoadCredentialsConfig(): ?array {
    static $loaded = false;
    static $config = null;

    if ($loaded) {
        return $config;
    }
    $loaded = true;

    $inlineJson = getenv('GOOGLE_CLOUD_CREDENTIALS_JSON') ?: getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
    if (is_string($inlineJson) && trim($inlineJson) !== '') {
        $decoded = json_decode($inlineJson, true);
        if (is_array($decoded)) {
            $config = $decoded;
            return $config;
        }
    }

    $jsonPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (is_string($jsonPath) && $jsonPath !== '' && file_exists($jsonPath)) {
        $decoded = json_decode((string)file_get_contents($jsonPath), true);
        if (is_array($decoded)) {
            $config = $decoded;
            return $config;
        }
    }

    return null;
}

function googleVisionIsConfigured(): bool {
    return googleVisionLoadCredentialsConfig() !== null
        && class_exists(ServiceAccountCredentials::class);
}

function googleVisionFetchAccessToken(): array {
    if (!class_exists(ServiceAccountCredentials::class)) {
        return [
            'success' => false,
            'error' => 'Google Auth library not available',
        ];
    }

    $config = googleVisionLoadCredentialsConfig();
    if (!$config) {
        return [
            'success' => false,
            'error' => 'Google Vision credentials not configured',
        ];
    }

    try {
        $creds = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/cloud-platform'],
            $config
        );
        $token = $creds->fetchAuthToken();
        $accessToken = $token['access_token'] ?? null;
        if (!$accessToken) {
            return [
                'success' => false,
                'error' => 'Could not fetch Google access token',
            ];
        }

        return [
            'success' => true,
            'accessToken' => $accessToken,
            'projectId' => $config['project_id'] ?? null,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => 'Google auth error: ' . $e->getMessage(),
        ];
    }
}

function googleVisionExtractDocumentText(string $imageBase64, string $mime = 'image/jpeg'): array {
    $auth = googleVisionFetchAccessToken();
    if (!($auth['success'] ?? false)) {
        return [
            'success' => false,
            'error' => $auth['error'] ?? 'Google auth failed',
        ];
    }

    $endpoint = getenv('GOOGLE_VISION_ENDPOINT') ?: 'https://vision.googleapis.com/v1/images:annotate';
    $feature = getenv('GOOGLE_VISION_FEATURE') ?: 'DOCUMENT_TEXT_DETECTION';
    $languageHints = array_values(array_filter(array_map('trim', explode(',', getenv('GOOGLE_VISION_LANGUAGE_HINTS') ?: 'en,hi'))));

    $payload = [
        'requests' => [[
            'image' => ['content' => $imageBase64],
            'features' => [[
                'type' => $feature,
                'maxResults' => 1,
            ]],
            'imageContext' => [
                'languageHints' => $languageHints,
            ],
        ]],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $auth['accessToken'],
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return [
            'success' => false,
            'error' => 'Google OCR request failed: ' . $curlErr,
        ];
    }

    $data = json_decode((string)$response, true);
    if ($httpCode !== 200) {
        $error = $data['error']['message'] ?? 'Google OCR API error';
        return [
            'success' => false,
            'error' => $error,
        ];
    }

    $ocr = $data['responses'][0] ?? [];
    $text = trim((string)(
        $ocr['fullTextAnnotation']['text']
        ?? $ocr['textAnnotations'][0]['description']
        ?? ''
    ));

    return [
        'success' => true,
        'text' => $text,
        'provider' => 'google_vision',
        'mime' => $mime,
        'projectId' => $auth['projectId'] ?? null,
    ];
}
