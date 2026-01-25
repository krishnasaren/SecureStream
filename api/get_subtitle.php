<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/secure_drm.php';



$referer = $_SERVER['HTTP_REFERER'] ?? '';

if (!$referer) {
    http_response_code(403);
    exit("Method not allowed");
}

if (parse_url($referer, PHP_URL_HOST) !== parse_url('//' . $_SERVER['HTTP_HOST'], PHP_URL_HOST)) {
    http_response_code(403);
    exit("Invalid Requests");
}

$auth = new Auth();
if (!$auth->getCurrentUser()) {
    http_response_code(401);
    exit;
}

$videoId = $_GET['video_id'] ?? '';
$subIndex = isset($_GET['index']) ? intval($_GET['index']) : -1;

$token = $_GET['token'] ?? '';

$securePlaybackManager = new SecurePlaybackManager();

if($securePlaybackManager->validateSession($token)['valid'] === false) {
    http_response_code(499);
    exit("Invalid session token");
}

if (empty($videoId) || $subIndex < 0) {
    http_response_code(400);
    exit;
}

if (str_contains($videoId, '..') || str_contains($videoId, '/')) {
    http_response_code(403);
    exit;
}

// Get video info to find subtitle file
$videoInfo = getVideoInfo($videoId);

if (!$videoInfo || empty($videoInfo['subtitle_tracks'][$subIndex])) {
    http_response_code(404);
    exit;
}


$subTrack = $videoInfo['subtitle_tracks'][$subIndex];
$subFile = ENCRYPTED_DIR . $videoId . '/subtitles/' . $subTrack['file'];

if (!is_file($subFile)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/vtt; charset=utf-8');
header('Content-Length: ' . filesize($subFile));
header('Cache-Control: public, max-age=31536000');

readfile($subFile);



/*
$plaintext = file_get_contents($subFile);

// 2. Derive AES key from session token
$sessionToken = $token  ?? '';
if (!$sessionToken) {
    http_response_code(403);
    exit;
}

$key = hash('sha256', $sessionToken, true); // 32 bytes

// 3. Generate IV
$iv = random_bytes(12);

// 4. Encrypt
$ciphertext = openssl_encrypt(
    $plaintext,
    'aes-256-gcm',
    $key,
    OPENSSL_RAW_DATA,
    $iv,
    $tag
);

// 5. Send encrypted payload (iv + tag + ciphertext)
$payload = $iv . $tag . $ciphertext;

header('Content-Type: application/octet-stream');
header('Content-Length: ' . strlen($payload));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo $payload;
exit;*/



?>