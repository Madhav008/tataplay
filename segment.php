<?php
include 'app/functions.php';

// Log the incoming request
error_log("[segment] Incoming request: " . $_SERVER['REQUEST_URI']);

// Parse URI parts
$uriParts = explode('/', $_SERVER['REQUEST_URI']);
$segmentWithQuery = $uriParts[3] ?? null;

if (!$segmentWithQuery) {
    http_response_code(400);
    echo "Missing segment path.";
    exit;
}

// Parse segment filename and query string
$parsedUrl = parse_url($_SERVER['REQUEST_URI']);

preg_match('~^/tataplay/(\d+)~', $parsedUrl['path'], $matches);
$media_id = $matches[1] ?? null;
// error_log("[segment] media_id: $media_id");

$cacheData = file_exists($cachePath) ? json_decode(file_get_contents($cachePath), true) : [];

$baseUrl = '';
$host = '';

if (isset($cacheData[$media_id])) {
    $cachedUrl = $cacheData[$media_id]['url'];
    $baseUrl = rtrim(preg_replace('/manifest\.mpd.*/', 'dash', $cachedUrl), '/');
    // $baseUrl = str_replace('//', '/', $baseUrl);
    // error_log("[segment] Base URL: $baseUrl");
    $baseUrl = rtrim(preg_replace('/manifest\.mpd.*/', 'dash', $cachedUrl), '/');

    // ❌ DO NOT do str_replace('//', '/', $baseUrl);

    error_log("[segment] Base URL: $baseUrl");

    // Correctly extract host
    $host = parse_url($baseUrl, PHP_URL_HOST);
    error_log("[segment] Host: $host");

} else {
    error_log("[segment] No cached URL found for media ID: $media_id");
    http_response_code(404);
    echo "No cached URL found for media ID: $media_id";
    exit;
}

$segmentPath = basename($parsedUrl['path']);
$query = $parsedUrl['query'] ?? '';
// error_log("[segment] Segment path: $segmentPath, Query: $query");

// Extract hdntl token & real channel ID from `acl`
parse_str($query, $queryParts);
$hdntl = $queryParts['hdntl'] ?? '';

if (!preg_match('~/irdeto_com_Channel_(\d+)/~', $hdntl, $matches)) {
    http_response_code(400);
    echo "Invalid token or unable to extract channel ID.";
    exit;
}
$channelId = $matches[1];

// Construct original Akamai segment URL
$originalSegmentUrl = "{$baseUrl}/{$segmentPath}";
if ($query) {
    $originalSegmentUrl .= '?' . $query;
}

error_log("[segment] Proxying to: $originalSegmentUrl");

// Initialize cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $originalSegmentUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
        "Accept-Encoding: identity",
        "Connection: Keep-Alive",
        "Host: $host",
        "Origin: https://watch.tataplay.com",
        "Referer: https://watch.tataplay.com/",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
        "X-Forwarded-For: 59.178.74.184"
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle failure
if ($response === false || $httpCode !== 200) {
    error_log("[segment] Curl failed: $curlError, HTTP code: $httpCode");
    http_response_code($httpCode ?: 502);
    echo "Failed to fetch segment. Code: $httpCode";
    exit;
}

// Serve segment with appropriate headers
header("Content-Type: video/mp4");
header("Access-Control-Allow-Origin: *");
echo $response;
