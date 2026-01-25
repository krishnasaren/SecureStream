<?php

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Set to 1 in production with HTTPS
ini_set('session.cookie_samesite', 'Strict');


// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

//------------------------Session-------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}





// Define base path
define('BASE_PATH', dirname(__DIR__));

// Define all paths
define('VIDEOS_DIR', BASE_PATH . '/videos/');
define('ENCRYPTED_DIR', VIDEOS_DIR . 'encrypted/');
define('KEYS_DIR', VIDEOS_DIR . 'keys/');
define('THUMBNAILS_DIR', VIDEOS_DIR . 'thumbnails/');
define('ORIGINAL_DIR', VIDEOS_DIR . 'original/');
define('USERS_FILE', BASE_PATH . '/users/users.json');
define('MASTER_KEY_FILE', BASE_PATH . '/server_master.key');

//echo(dirname(__DIR__));

// Ensure all directories exist
$directories = [
    VIDEOS_DIR,
    ENCRYPTED_DIR,
    KEYS_DIR,
    THUMBNAILS_DIR,
    ORIGINAL_DIR,
    dirname(USERS_FILE)
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Encryption settings
define('ENCRYPTION_METHOD', 'AES-256-CTR');
define('CHUNK_SIZE_SECONDS', 10); // Split into 10-second chunks



// ==================================================
define('APP_SECRET_FILE', BASE_PATH . '/app_secret.key');

if (!file_exists(APP_SECRET_FILE)) {
    // Generate a strong 32-byte secret ONCE
    $appSecret = random_bytes(32);
    file_put_contents(APP_SECRET_FILE, base64_encode($appSecret));
    chmod(APP_SECRET_FILE, 0600);
}

$appSecret = base64_decode(file_get_contents(APP_SECRET_FILE));

if ($appSecret === false || strlen($appSecret) !== 32) {
    die('Invalid APP_SECRET length');
}

define('APP_SECRET', $appSecret);

// Get or create master key
function getMasterKey()
{
    if (!file_exists(MASTER_KEY_FILE)) {
        // Generate a new master key
        $masterKey = random_bytes(32);
        file_put_contents(MASTER_KEY_FILE, base64_encode($masterKey));
        chmod(MASTER_KEY_FILE, 0600); // Read/write only by owner
    }

    $masterKey = base64_decode(file_get_contents(MASTER_KEY_FILE));
    if (strlen($masterKey) !== 32) {
        die("Invalid master key length");
    }

    return $masterKey;
}

// Simple authentication check
function isAuthenticated()
{
    return isset($_SESSION['user_id']) &&
        isset($_SESSION['authenticated']) &&
        $_SESSION['authenticated'] === true;
}

// Redirect if not authenticated
function requireAuth()
{
    if (!isAuthenticated()) {
        header('Location: ../pages/login.php');
        exit;
    }
}

// Get video info
function getVideoInfo($videoId)
{
    $infoFile = ENCRYPTED_DIR . $videoId . '/info.json';
    if (file_exists($infoFile)) {
        return json_decode(file_get_contents($infoFile), true);
    }
    return null;
}

//echo(getVideoInfo("video_1769094415")['title']);

// Get all videos
function getAllVideos()
{
    $videos = [];

    if (!file_exists(ENCRYPTED_DIR)) {
        return $videos;
    }

    $videoDirs = glob(ENCRYPTED_DIR . '*/', GLOB_ONLYDIR);

    foreach ($videoDirs as $dir) {
        $videoId = basename($dir);
        $infoFile = $dir . 'info.json';

        if (file_exists($infoFile)) {
            $info = json_decode(file_get_contents($infoFile), true);
            $videos[$videoId] = [
                'id' => $videoId,
                'title' => $info['title'] ?? 'Unknown Title',
                'description' => $info['description'] ?? '',
                'created_at' => $info['created_at'] ?? date('Y-m-d H:i:s'),
                'chunks' => $info['chunk_count'] ?? 0,
                'thumbnail' => file_exists(THUMBNAILS_DIR . $videoId . '.jpg') ?
                    '/stream/videos/thumbnails/' . $videoId . '.jpg' :
                    'assets/default-thumb.jpg'
            ];
        }
    }

    return $videos;
}


function createTimeBoundToken(string $videoId, string $userId, int $ttlSeconds = 60): string
{
    $expiry = time() + $ttlSeconds;
    $random = bin2hex(random_bytes(8)); // entropy

    $payload = json_encode([
        'vid' => $videoId,
        'uid' => $userId,
        'exp' => $expiry,
        'rnd' => $random
    ]);

    $payloadB64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

    $secret = APP_SECRET; // never expose
    $signature = hash_hmac('sha256', $payloadB64, $secret, true);
    $signatureB64 = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return $payloadB64 . '.' . $signatureB64;
}
function validateTimeBoundToken(string $token): array|false
{
    if (!str_contains($token, '.')) {
        return false;
    }

    [$payloadB64, $sigB64] = explode('.', $token, 2);

    $secret = APP_SECRET;
    $expectedSig = hash_hmac('sha256', $payloadB64, $secret, true);
    $expectedSigB64 = rtrim(strtr(base64_encode($expectedSig), '+/', '-_'), '=');

    if (!hash_equals($expectedSigB64, $sigB64)) {
        return false; // tampered
    }

    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
    if (!$payload || time() > $payload['exp']) {
        return false; // expired
    }

    return $payload;
}


// Security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");


