<?php
error_log("[segment] Incoming request: " . $_SERVER['REQUEST_URI']);

// Parse URI and extract ID and segment
$uriParts = explode('/', $_SERVER['REQUEST_URI']);
$id = $uriParts[2] ?? null;
$segmentWithQuery = $uriParts[3] ?? null;

if (!$id || !$segmentWithQuery) {
    http_response_code(400);
    echo "Missing ID or segment path.";
    exit;
}

$parsedUrl = parse_url($_SERVER['REQUEST_URI']);
$segmentPath = basename($parsedUrl['path']); // e.g., AndTV_HD-audio_94482_und=94000-218729180.m4s
$query = $parsedUrl['query'] ?? '';


// Extract real channel ID from hdntl token
parse_str($query, $queryParts);
$hdntl = $queryParts['hdntl'] ?? '';
$matches = [];
if (preg_match('~/irdeto_com_Channel_(\d+)/~', $hdntl, $matches)) {
    $correctChannelId = $matches[1]; // use this instead of $id from path
} else {
    http_response_code(400);
    echo "Invalid token or unable to extract channel ID.";
    exit;
}

$originalSegmentUrl = "https://bpaita4.akamaized.net/bpk-tv/irdeto_com_Channel_{$correctChannelId}/output/dash/{$segmentPath}";
if ($query) {
    $originalSegmentUrl .= '?' . $query;
}



error_log("[segment] Proxying to: $originalSegmentUrl");

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $originalSegmentUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Proper headers
$headers = [
    "Accept-Encoding: identity",
    "Connection: Keep-Alive",
    "Host: bpaita4.akamaized.net",
    "Origin: https://watch.tataplay.com",
    "Referer: https://watch.tataplay.com/",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
    "X-Forwarded-For: 59.178.74.184"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Optional debug logging
// curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false || $httpCode !== 200) {
    error_log("[segment] Curl failed: " . curl_error($ch));
    http_response_code($httpCode ?: 502);
    echo "Failed to fetch segment. Code: $httpCode";
    exit;
}

curl_close($ch);

// Serve the content
header("Content-Type: video/mp4"); // you can also use application/octet-stream
header("Access-Control-Allow-Origin: *");
echo $response;
