<?php
// openai-proxy.php
// This script receives a JSON payload with a Base64 image and a prompt,
// sends it to OpenAI's Vision API, and returns the AI's response.

// Set appropriate headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // IMPORTANT: Adjust this to your specific frontend URL(s) for security in production!
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- Configuration ---
// IMPORTANT: Replace 'YOUR_OPENAI_API_KEY' with your actual OpenAI API Key.
// For production, it's highly recommended to use environment variables
// rather than hardcoding the key here.
$openaiApiKey = getenv('OPENAI_API_KEY');

$openaiEndpoint = 'https://api.openai.com/v1/chat/completions';
// $openaiModel = 'gpt-4o-mini'; // Model should come from the frontend requestData now, not fixed here

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data (this is already the JSON string from the frontend)
    $input = file_get_contents('php://input');
    
    // Attempt to decode just for basic validation, but will send the raw $input to OpenAI
    $requestData = json_decode($input, true); 

    // Validate incoming data (now using $requestData for validation, but $input for sending)
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input received by proxy.']);
        exit();
    }

    if (empty($requestData['model']) || empty($requestData['messages'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing model or messages in frontend request.']);
        exit();
    }
    
    // Ensure the OpenAI API key is set
    if ($openaiApiKey === 'YOUR_OPENAI_API_KEY' || empty($openaiApiKey)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'OpenAI API Key is not configured in the proxy.']);
        exit();
    }

    // --- Prepare for OpenAI API ---
    $ch = curl_init($openaiEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $input); // <<< --- CRITICAL CHANGE HERE: Send the raw JSON input directly
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openaiApiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'cURL error: ' . $curlError]);
        exit();
    }

    // Pass OpenAI API errors back to the client if httpCode is not 200
    http_response_code($httpCode);
    echo $response; // Return the raw JSON response from OpenAI directly

} else {
    // Handle invalid request method
    http_response_code(405); // Method Not Allowed
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid request method. Only POST requests are allowed.'
    ]);
}
?>