<?php
// Get channel_id from query string
$channel_id = isset($_GET['channel_id']) ? $_GET['channel_id'] : '';

error_log("[license] Processing request for channel_id: $channel_id");
// If channel_id is missing, return error
if (empty($channel_id)) {
    header('Content-Type: application/json');
    http_response_code(400);
    error_log("[license] Error: Missing channel_id");
    echo json_encode(['error' => 'Missing channel_id']);
    exit;
}

// Build the original license server URL
$original_license_url = "https://tp.drmlive-01.workers.dev/?id={$channel_id}";
error_log("[license] License server URL: $original_license_url");

// Cache directory and file
$cache_dir = __DIR__ . '/cache_license';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0777, true);
    error_log("[license] Created cache directory: $cache_dir");
}
$cache_file = "{$cache_dir}/license_{$channel_id}.json";
error_log("[license] Cache file: $cache_file");

// Load cache file as array of licenses (by key_id)
$cache_data = [];
if (file_exists($cache_file)) {
    $cache_data = json_decode(file_get_contents($cache_file), true);
    if (!is_array($cache_data)) {
        error_log("[license] Cache file invalid, resetting cache.");
        $cache_data = [];
    } else {
        error_log("[license] Loaded cache file with " . count($cache_data) . " entries.");
    }
}

// Get the request body (for POST requests)
$request_body = file_get_contents('php://input');
error_log("[license] Request body: $request_body");

// Try to extract key_id from request body (assume JSON with "kids" array)
$key_id = null;
$request_json = json_decode($request_body, true);
if (is_array($request_json) && isset($request_json['kids'][0])) {
    $key_id = $request_json['kids'][0];
    error_log("[license] Extracted key_id: $key_id");
} else {
    error_log("[license] No key_id found in request.");
}

// Check cache for this key_id (no expiry check)
if ($key_id && isset($cache_data[$key_id])) {
    $entry = $cache_data[$key_id];
    $body = json_encode($entry['license'], JSON_UNESCAPED_SLASHES);
    $headers = $entry['headers'] ?? [];
    $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    $send_gzip = false;

    // Check if client accepts gzip
    if (stripos($accept_encoding, 'gzip') !== false) {
        // Gzip the body and set header
        $body = gzencode($body);
        $send_gzip = true;
    }

    foreach ($headers as $header) {
        if (stripos($header, 'Content-Encoding:') === 0) {
            if ($send_gzip) {
                header('Content-Encoding: gzip');
            }
            // Skip if not sending gzip
            continue;
        }
        header($header);
    }
    // Always set Content-Type
    header('Content-Type: application/json');

    error_log("[license] Served from cache for key_id $key_id: $body");
    echo $body;
    exit;
}

// Forward only relevant headers
$forward_headers = [];
$allowed_headers = [
    'Accept',
    'Accept-Encoding',
    'Content-Type',
    'Origin',
    'Referer',
    'User-Agent',
    'X-Forwarded-For'
];
foreach (getallheaders() as $key => $value) {
    if (in_array($key, $allowed_headers)) {
        $forward_headers[] = "$key: $value";
    }
}
error_log("[license] Forwarding headers: " . json_encode($forward_headers));

// Initialize cURL
$ch = curl_init($original_license_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $forward_headers);
curl_setopt($ch, CURLOPT_HEADER, true); // Get headers + body

// Execute the request
$response = curl_exec($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header = substr($response, 0, $header_size);
$body = substr($response, $header_size);

error_log("[license] Response headers: " . str_replace("\r\n", " | ", $header));
error_log("[license] Raw response body: $body");

// Parse response headers
$response_headers = [];
$is_gzip = false;
foreach (explode("\r\n", $header) as $response_header) {
    if (
        stripos($response_header, 'Content-Type:') === 0 ||
        stripos($response_header, 'Content-Encoding:') === 0
    ) {
        $response_headers[] = $response_header;
        if (stripos($response_header, 'Content-Encoding: gzip') === 0) {
            $is_gzip = true;
        }
    }
}

// Always decompress for cache/decoding
$decompressed_body = $body;
if ($is_gzip) {
    $try_decompress = @gzdecode($body);
    if ($try_decompress !== false) {
        $decompressed_body = $try_decompress;
        error_log("[license] Decompressed gzip for cache/decoding.");
    } else {
        error_log("[license] Failed to decompress gzip for cache/decoding.");
    }
}

// Set HTTP response code
if (!curl_errno($ch)) {
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    http_response_code($http_code);
    error_log("[license] HTTP response code: $http_code");
} else {
    error_log("[license] cURL error: " . curl_error($ch));
}
curl_close($ch);

// Forward response headers
foreach ($response_headers as $response_header) {
    header($response_header);
}

// Log the response body
error_log("[license] Final response body: $decompressed_body");

// Parse JSON for caching
$json = json_decode($decompressed_body, true);

// Store new entry in cache only if key_id is present and response is valid JSON
if ($key_id && is_array($json)) {
    $cache_data[$key_id] = [
        'license' => $json, // store as decoded JSON, not as string
        'headers' => $response_headers
    ];
    // Save cache file with pretty print and unescaped slashes
    $cache_json = json_encode($cache_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($cache_json !== false) {
        if (file_put_contents($cache_file, $cache_json, LOCK_EX) !== false) {
            error_log("[license] Cached license for key_id $key_id.");
        } else {
            error_log("[license] Failed to write cache file for key_id $key_id.");
        }
    } else {
        error_log("[license] Failed to encode cache JSON for key_id $key_id");
    }
}

// Output to client: send compressed or decompressed based on Accept-Encoding
$accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
if ($is_gzip && stripos($accept_encoding, 'gzip') === false) {
    echo $decompressed_body;
} else {
    echo $body;
}