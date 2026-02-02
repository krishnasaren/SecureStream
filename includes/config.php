<?php

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
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
define('PLAYBACK_LOGS_DIR', BASE_PATH . '/logs/');

// Ensure all directories exist
$directories = [
    VIDEOS_DIR,
    ENCRYPTED_DIR,
    KEYS_DIR,
    THUMBNAILS_DIR,
    ORIGINAL_DIR,
    PLAYBACK_LOGS_DIR,
    dirname(USERS_FILE)
];

foreach ($directories as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Encryption settings
define('ENCRYPTION_METHOD', 'AES-256-CTR');
define('CHUNK_SIZE_SECONDS', 10);

// ==================================================
define('APP_SECRET_FILE', BASE_PATH . '/app_secret.key');

if (!file_exists(APP_SECRET_FILE)) {
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
        $masterKey = random_bytes(32);
        file_put_contents(MASTER_KEY_FILE, base64_encode($masterKey));
        chmod(MASTER_KEY_FILE, 0600);
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

// Get video info with proper chunk analysis - OPTIMIZED
function getVideoInfo($videoId)
{
    $infoFile = ENCRYPTED_DIR . $videoId . '/info.json';
    if (!file_exists($infoFile)) {
        return null;
    }

    $info = json_decode(file_get_contents($infoFile), true);

    // Dynamically calculate chunk counts per quality and stream if not present
    if ($info && !isset($info['chunk_map'])) {
        $info = updateChunkInfo($videoId, $info);
    }

    return $info;
}

// Update chunk information dynamically - OPTIMIZED VERSION
function updateChunkInfo($videoId, $info)
{
    $videoDir = ENCRYPTED_DIR . $videoId . '/';
    $chunkMap = [];

    if (isset($info['qualities'])) {
        foreach ($info['qualities'] as $quality) {
            $qualityDir = $videoDir . $quality . '/';

            if (is_dir($qualityDir)) {
                // Find max chunk numbers for each stream (more efficient)
                $videoChunkCount = 0;
                $audioStreams = [];
                $allChunks = glob($qualityDir . 'chunk-stream*.enc');

                foreach ($allChunks as $chunk) {
                    if (preg_match('/chunk-stream(\d+)-(\d+)\.enc$/', basename($chunk), $matches)) {
                        $streamId = (int) $matches[1];
                        $chunkNum = (int) $matches[2];

                        if ($streamId === 0) {
                            $videoChunkCount = max($videoChunkCount, $chunkNum);
                        } else {
                            if (!isset($audioStreams[$streamId])) {
                                $audioStreams[$streamId] = 0;
                            }
                            $audioStreams[$streamId] = max($audioStreams[$streamId], $chunkNum);
                        }
                    }
                }

                // Store only counts, not individual chunk numbers
                $chunkMap[$quality] = [
                    'video' => $videoChunkCount,
                    'audio' => []
                ];

                foreach ($audioStreams as $streamId => $maxChunk) {
                    $chunkMap[$quality]['audio'][] = [
                        'count' => $maxChunk,
                        'stream_id' => $streamId
                    ];
                }
            }
        }
    }

    $info['chunk_map'] = $chunkMap;

    // Calculate max chunks across all streams for each quality
    $info['quality_max_chunks'] = [];
    foreach ($chunkMap as $quality => $qualityData) {
        $qualityMax = $qualityData['video'];
        if (isset($qualityData['audio']) && is_array($qualityData['audio'])) {
            foreach ($qualityData['audio'] as $audioData) {
                $qualityMax = max($qualityMax, $audioData['count']);
            }
        }
        $info['quality_max_chunks'][$quality] = $qualityMax;
    }

    // Save updated info
    file_put_contents(
        ENCRYPTED_DIR . $videoId . '/info.json',
        json_encode($info, JSON_PRETTY_PRINT)
    );

    return $info;
}

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
                'qualities' => $info['qualities'] ?? [],
                'thumbnail' => file_exists(THUMBNAILS_DIR . $videoId . '.jpg') ?
                    '/stream/videos/thumbnails/' . $videoId . '.jpg' :
                    'assets/default-thumb.jpg'
            ];
        }
    }

    return $videos;
}

// Time-bound token functions
function createTimeBoundToken(string $videoId, string $userId, int $ttlSeconds = 60): string
{
    $expiry = time() + $ttlSeconds;
    $random = bin2hex(random_bytes(8));

    $payload = json_encode([
        'vid' => $videoId,
        'uid' => $userId,
        'exp' => $expiry,
        'rnd' => $random
    ]);

    $payloadB64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

    $secret = APP_SECRET;
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
        return false;
    }

    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
    if (!$payload || time() > $payload['exp']) {
        return false;
    }

    return $payload;
}

// Get chunk information for specific stream
function getStreamChunkInfo($videoId, $quality, $streamId)
{
    $info = getVideoInfo($videoId);
    if (!$info || !isset($info['chunk_map'][$quality])) {
        return null;
    }

    if ($streamId === 0) {
        return [
            'count' => $info['chunk_map'][$quality]['video'],
            'stream_id' => 0
        ];
    }

    if (isset($info['chunk_map'][$quality]['audio'])) {
        foreach ($info['chunk_map'][$quality]['audio'] as $audioInfo) {
            if ($audioInfo['stream_id'] == $streamId) {
                return $audioInfo;
            }
        }
    }

    return null;
}

// Security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate");
?>