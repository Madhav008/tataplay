<?php

include 'app/functions.php';

//error_log("[mpd] Script started for ID: $id");

if (!$id) {
    //error_log("[mpd] Missing content ID.");
    http_response_code(400);
    echo 'Missing content ID.';
    exit;
}

if (!file_exists($loginFilePath)) {
    //error_log("[mpd] Login file not found: $loginFilePath");
    http_response_code(401);
    echo 'Login required.';
    exit;
}

$loginData = json_decode(file_get_contents($loginFilePath), true);
if (!isset($loginData['data']['subscriberId']) || !isset($loginData['data']['userAuthenticateToken'])) {
    //error_log("[mpd] Invalid login data structure.");
    http_response_code(403);
    echo 'Invalid login data.';
    exit;
}

$subscriberId = $loginData['data']['subscriberId'];
$userToken = $loginData['data']['userAuthenticateToken'];

$cacheData = file_exists($cachePath) ? json_decode(file_get_contents($cachePath), true) : [];
$useCache = false;

if (isset($cacheData[$id])) {
    $cachedUrl = $cacheData[$id]['url'];
    parse_str(parse_url($cachedUrl, PHP_URL_QUERY), $queryParams);
    $exp = isset($queryParams['hdntl']) ? null : ($queryParams['exp'] ?? null);
    if (isset($queryParams['hdntl'])) {
        parse_str(str_replace('~', '&', $queryParams['hdntl']), $hdntlParams);
        $exp = $hdntlParams['exp'] ?? null;
    }
    if ($exp && is_numeric($exp) && time() < (int) $exp) {
        $mpdurl = $cachedUrl;
        $useCache = true;
        //error_log("[mpd] Using cached URL: $mpdurl");
    }
}

if (!$useCache) {
    $headers = [
        'Authorization: Bearer ' . $userToken,
        'subscriberId: ' . $subscriberId,
    ];

    //error_log("[mpd] Fetching new content. Headers: " . json_encode($headers));

    $options = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
        ],
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($content_api, false, $context);

    if ($response === false) {
        //error_log("[mpd] Failed to fetch content data from: $content_api");
        http_response_code(500);
        echo 'Failed to fetch content data.';
        exit;
    }

    $responseData = json_decode($response, true);
    if (!isset($responseData['data']['dashPlayreadyPlayUrl'])) {
        //error_log("[mpd] dashPlayreadyPlayUrl not found in API response.");
        http_response_code(404);
        echo 'dashPlayreadyPlayUrl not found.';
        exit;
    }

    $encrypteddashUrl = $responseData['data']['dashPlayreadyPlayUrl'];
    $decryptedUrl = decryptUrl($encrypteddashUrl, $aesKey);
    $decryptedUrl = str_replace('bpaicatchupta', 'bpaita', $decryptedUrl);

    //error_log("[mpd] Decrypted URL: $decryptedUrl");

    if (strpos($decryptedUrl, 'bpaita') === false) {
        //error_log("[mpd] Redirecting to final URL (non-standard): $decryptedUrl");
        header("Location: $decryptedUrl");
        exit;
    }

    $getheaders = get_headers($decryptedUrl, 1, stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: $ua\r\n",
            'follow_location' => 0,
            'ignore_errors' => true
        ]
    ]));

    if (!$getheaders || !isset($getheaders['Location'])) {
        //error_log("[mpd] No redirection. Using decrypted URL: $decryptedUrl");
        header("Location: $decryptedUrl", true, 302);
        exit;
    }

    $location = is_array($getheaders['Location']) ? end($getheaders['Location']) : $getheaders['Location'];
    $mpdurl = strpos($location, '&') !== false ? substr($location, 0, strpos($location, '&')) : $location;

    //error_log("[mpd] Final MPD URL: $mpdurl");

    $cacheData[$id] = ['url' => $mpdurl, 'updated_at' => time()];
    file_put_contents($cachePath, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$mpdContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: $ua\r\nReferer: https://watch.tataplay.com/\r\nOrigin: https://watch.tataplay.com\r\n",
        'ignore_errors' => true
    ]
]);

$mpdContent = @file_get_contents($mpdurl, false, $mpdContext);

if ($mpdContent === false) {
    //error_log("[mpd] Failed to fetch MPD manifest from: $mpdurl");
    http_response_code(500);
    echo 'Failed to fetch MPD content.';
    exit;
}
//error_log("[mpd] MPD manifest fetched successfully.");

$baseUrl = dirname($mpdurl);
$GetPssh = extractPsshFromManifest($mpdContent, $baseUrl, $ua);
//error_log("[mpd] PSSH extraction completed. Data: " . json_encode($GetPssh));
$processedManifest = str_replace('dash/', "$baseUrl/dash/", $mpdContent);

if ($GetPssh) {
    //error_log("[mpd] DRM PSSH extraction successful. Inserting into manifest.");
    $processedManifest = str_replace('mp4protection:2011', 'mp4protection:2011" cenc:default_KID="' . $GetPssh['kid'], $processedManifest);
    $processedManifest = str_replace('" value="PlayReady"/>', '"><cenc:pssh>' . $GetPssh['pr_pssh'] . '</cenc:pssh></ContentProtection>', $processedManifest);
    $processedManifest = str_replace('" value="Widevine"/>', '"><cenc:pssh>' . $GetPssh['pssh'] . '</cenc:pssh></ContentProtection>', $processedManifest);
} else {
    //error_log("[mpd] No PSSH data found.");
}

header('Content-Security-Policy: default-src \'self\';');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/dash+xml');
header('Content-Disposition: attachment; filename="tp' . urlencode($id) . '.mpd"');
// Load the MPD manifest as XML
$dom = new DOMDocument();
libxml_use_internal_errors(true); // Prevent output on malformed XML

if ($dom->loadXML($processedManifest)) {
    $baseUrls = $dom->getElementsByTagName('BaseURL');

    if ($baseUrls->length > 0) {
        $originalBaseUrl = trim($baseUrls->item(0)->nodeValue);
        //error_log("[mpd] Original BaseURL: $originalBaseUrl");

        // Ensure trailing slash is removed for consistency
        $originalBaseUrl = rtrim($originalBaseUrl, '/');

        // Construct proxy base URL
        $proxyBaseUrl = "http://192.168.1.68/tataplay/{$id}/?baseurl={$originalBaseUrl}/";
    } else {
        //error_log("[mpd] <BaseURL> element not found in MPD manifest.");
        $proxyBaseUrl = '';
    }
} else {
    //error_log("[mpd] Failed to parse MPD XML.");
    $proxyBaseUrl = '';
}

// Replace <BaseURL> if it exists
$processedManifest = preg_replace(
    '#<BaseURL>.*?</BaseURL>#',
    "<BaseURL>$proxyBaseUrl</BaseURL>",
    $processedManifest
);

// Save final processed MPD file locally for debugging
// $savePath = __DIR__ . "./final_mpd_{$id}.mpd";
// file_put_contents($savePath, $processedManifest);
// error_log("[mpd] Final MPD file saved at: $savePath");

echo $processedManifest;
