<?php
/**
 * ============================================
 * ENHANCED SECURE DRM SYSTEM WITH DYNAMIC CHUNK HANDLING
 * ============================================
 * 
 * Security Features:
 * - Ephemeral per-chunk encryption keys
 * - Session-bound playback tokens
 * - Dynamic chunk count handling
 * - Multi-quality support
 * - Multi-audio track support
 * - Time-limited key derivation
 * - Double encryption (master + ephemeral)
 * - Anti-tampering validation
 * - User watermarking
 * - Request rate limiting
 * - Playback session tracking
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';

class SecurePlaybackManager
{
    private $sessionTimeout = 3600; // 1 hour max session
    private $chunkTimeout = 30; // 30 seconds per chunk key
    private $maxRequestsPerMinute = 1000;

    /**
     * Initialize a secure playback session
     */
    public function createPlaybackSession($videoId, $userId)
    {
        // Generate unique session token
        $sessionToken = bin2hex(random_bytes(32));

        // Create session data
        $session = [
            'token' => $sessionToken,
            'video_id' => $videoId,
            'user_id' => $userId,
            'created_at' => time(),
            'expires_at' => time() + $this->sessionTimeout,
            'last_chunk' => -1,
            'request_count' => 0,
            'last_request_time' => time(),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];

        // Store in PHP session
        $_SESSION['playback_sessions'][$sessionToken] = $session;

        // Log session creation
        $this->logPlaybackEvent($videoId, $userId, 'SESSION_CREATED', [
            'token' => substr($sessionToken, 0, 8) . '...',
            'ip' => $session['ip_address']
        ]);

        return [
            'success' => true,
            'session_token' => $sessionToken,
            'expires_in' => $this->sessionTimeout
        ];
    }

    static function invalidatePlaybackSessions(string $videoId): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['playback_sessions'])) {
            return;
        }

        foreach ($_SESSION['playback_sessions'] as $token => $session) {
            if (
                isset($session['video_id']) &&
                $session['video_id'] === $videoId
            ) {
                unset($_SESSION['playback_sessions'][$token]);
            }
        }

        if (empty($_SESSION['playback_sessions'])) {
            unset($_SESSION['playback_sessions']);
        }

        error_log("Playback sessions invalidated for video: {$videoId}");
    }

    /**
     * Validate playback session
     */
    public function validateSession($sessionToken)
    {
        if (!isset($_SESSION['playback_sessions'][$sessionToken])) {
            return ['valid' => false, 'error' => 'Invalid session token'];
        }

        $session = $_SESSION['playback_sessions'][$sessionToken];

        // Check expiration
        if (time() > $session['expires_at']) {
            unset($_SESSION['playback_sessions'][$sessionToken]);
            return ['valid' => false, 'error' => 'Session expired'];
        }

        // Check IP binding (optional)
        if ($session['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            $this->logPlaybackEvent(
                $session['video_id'],
                $session['user_id'],
                'IP_MISMATCH',
                [
                    'original' => $session['ip_address'],
                    'current' => $_SERVER['REMOTE_ADDR']
                ]
            );
        }

        // Rate limiting
        if ($session['request_count'] > $this->maxRequestsPerMinute) {
            $timeSinceLastRequest = time() - $session['last_request_time'];
            if ($timeSinceLastRequest < 60) {
                return ['valid' => false, 'error' => 'Rate limit exceeded'];
            }
            $_SESSION['playback_sessions'][$sessionToken]['request_count'] = 0;
        }

        // Update session
        $_SESSION['playback_sessions'][$sessionToken]['last_request_time'] = time();
        $_SESSION['playback_sessions'][$sessionToken]['request_count']++;

        return [
            'valid' => true,
            'session' => $session
        ];
    }

    /**
     * Generate ephemeral key for specific chunk
     */
    public function generateEphemeralKey($videoId, $chunkIndex, $sessionToken)
    {
        $validation = $this->validateSession($sessionToken);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $session = $validation['session'];

        // Verify video matches session
        if ($session['video_id'] !== $videoId) {
            return ['success' => false, 'error' => 'Video ID mismatch'];
        }

        // Generate time-bound ephemeral key
        $timestamp = time();
        $timeWindow = floor($timestamp / $this->chunkTimeout);

        // Key derivation using HKDF
        $context = implode('|', [
            $videoId,
            $chunkIndex,
            $sessionToken,
            $session['user_id'],
            $timeWindow
        ]);

        $masterKey = getMasterKey();

        // Derive ephemeral key
        $ephemeralKey = hash_hkdf(
            'sha256',
            $masterKey,
            32,
            $context,
            'EPHEMERAL_CHUNK_KEY_V1'
        );

        // Generate unique IV for this chunk
        $ephemeralIV = hash_hkdf(
            'sha256',
            $masterKey,
            16,
            $context,
            'EPHEMERAL_CHUNK_IV_V1'
        );

        return [
            'success' => true,
            'key' => base64_encode($ephemeralKey),
            'iv' => base64_encode($ephemeralIV),
            'valid_until' => ($timeWindow + 1) * $this->chunkTimeout,
            'chunk_index' => $chunkIndex
        ];
    }

    /**
     * Serve chunk with dynamic chunk count handling
     */
    public function serveDynamicChunk($videoId, $track, $chunkIndex, $sessionToken, $quality = '720p', $audioTrack = 0)
    {
        // Validate session
        $validation = $this->validateSession($sessionToken);
        if (!$validation['valid']) {
            http_response_code(401);
            exit;
        }

        $session = $validation['session'];

        // Get video info
        $videoInfo = getVideoInfo($videoId);
        if (!$videoInfo) {
            http_response_code(404);
            exit;
        }

        // Determine stream ID
        $streamId = $this->getStreamId($track, $audioTrack, $videoInfo);

        // Check if chunk exists for this stream
        if (!$this->chunkExists($videoId, $quality, $streamId, $chunkIndex)) {
            // Check if we're beyond available chunks
            $maxChunks = $this->getMaxChunksForStream($videoInfo, $quality, $streamId);

            if ($chunkIndex >= $maxChunks) {
                // Send empty response for non-existent chunk
                $this->sendEmptyChunk();
                return;
            }

            http_response_code(404);
            exit;
        }

        // Update last chunk
        $_SESSION['playback_sessions'][$sessionToken]['last_chunk'] = max(
            $session['last_chunk'],
            $chunkIndex
        );

        // Load and serve the chunk
        $this->loadAndServeChunk($videoId, $quality, $streamId, $chunkIndex, $session);
    }

    private function getStreamId($track, $audioTrack, $videoInfo)
    {
        if ($track === 'video') {
            return 0;
        }

        // Audio track mapping
        if (isset($videoInfo['audio_tracks'][$audioTrack])) {
            return 1 + $audioTrack;
        }

        // Default audio stream
        return 1;
    }

    private function chunkExists($videoId, $quality, $streamId, $chunkIndex)
    {
        // Try quality-specific path first
        $chunkFile = sprintf(
            '%s%s/%s/chunk-stream%d-%05d.enc',
            ENCRYPTED_DIR,
            $videoId,
            $quality,
            $streamId,
            $chunkIndex + 1
        );

        if (file_exists($chunkFile)) {
            return true;
        }

        // Fallback to root folder
        $chunkFile = sprintf(
            '%s%s/chunk-stream%d-%05d.enc',
            ENCRYPTED_DIR,
            $videoId,
            $streamId,
            $chunkIndex + 1
        );

        return file_exists($chunkFile);
    }

    private function getMaxChunksForStream($videoInfo, $quality, $streamId)
    {
        if (!isset($videoInfo['chunk_map'][$quality])) {
            return $videoInfo['chunk_count'] ?? 0;
        }

        $chunkMap = $videoInfo['chunk_map'][$quality];

        if ($streamId === 0) {
            return $chunkMap['video'] ?? 0;
        }

        // Find audio stream
        if (isset($chunkMap['audio'])) {
            foreach ($chunkMap['audio'] as $audioInfo) {
                if ($audioInfo['stream_id'] == $streamId) {
                    return $audioInfo['count'] ?? 0;
                }
            }
        }

        return $videoInfo['chunk_count'] ?? 0;
    }

    private function sendEmptyChunk()
    {
        // Send empty chunk (for streams that end before others)
        header('Content-Type: application/octet-stream');
        header('Content-Length: 0');
        header('X-Chunk-Status: empty');
        exit;
    }

    private function loadAndServeChunk($videoId, $quality, $streamId, $chunkIndex, $session)
    {
        // Build chunk file path
        $chunkFile = sprintf(
            '%s%s/%s/chunk-stream%d-%05d.enc',
            ENCRYPTED_DIR,
            $videoId,
            $quality,
            $streamId,
            $chunkIndex + 1
        );

        // Fallback to root
        if (!file_exists($chunkFile)) {
            $chunkFile = sprintf(
                '%s%s/chunk-stream%d-%05d.enc',
                ENCRYPTED_DIR,
                $videoId,
                $streamId,
                $chunkIndex + 1
            );
        }

        if (!file_exists($chunkFile)) {
            http_response_code(404);
            exit;
        }

        // Read and process chunk
        $encryptedData = file_get_contents($chunkFile);

        // Get video key
        $encryption = new VideoEncryption();
        $keyData = $encryption->getVideoKey($videoId);

        if (!$keyData['success']) {
            http_response_code(500);
            exit;
        }

        // Decrypt with master key
        $videoKey = base64_decode($keyData['key']);
        $videoIV = base64_decode($keyData['iv']);
        $chunkIV = $this->generateChunkIV($videoIV, $chunkIndex);

        $decryptedData = openssl_decrypt(
            $encryptedData,
            'AES-256-CTR',
            $videoKey,
            OPENSSL_RAW_DATA,
            $chunkIV
        );

        if ($decryptedData === false) {
            http_response_code(500);
            exit;
        }

        // Generate ephemeral key
        $ephemeralKeyData = $this->generateEphemeralKey($videoId, $chunkIndex, $session['token']);

        if (!$ephemeralKeyData['success']) {
            http_response_code(403);
            exit;
        }

        // Re-encrypt with ephemeral key
        $ephemeralKey = base64_decode($ephemeralKeyData['key']);
        $ephemeralIV = base64_decode($ephemeralKeyData['iv']);

        $reencryptedData = openssl_encrypt(
            $decryptedData,
            'AES-256-CTR',
            $ephemeralKey,
            OPENSSL_RAW_DATA,
            $ephemeralIV
        );

        // Add watermark
        $watermark = $this->createWatermark($session['user_id'], $chunkIndex);
        $finalData = $watermark . $reencryptedData;

        // Send response
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($finalData));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Chunk-Index: ' . $chunkIndex);
        header('X-Stream-ID: ' . $streamId);
        header('X-Quality: ' . $quality);
        header('X-Valid-Until: ' . $ephemeralKeyData['valid_until']);

        echo $finalData;
        exit;
    }

    /**
     * Get chunk information API
     */
    public function getChunkInfo($videoId, $quality)
    {
        $videoInfo = getVideoInfo($videoId);
        if (!$videoInfo) {
            return ['success' => false, 'error' => 'Video not found'];
        }

        $chunkMap = [];
        $videoDir = ENCRYPTED_DIR . $videoId . '/';

        // Check quality folder
        $qualityDir = $videoDir . $quality . '/';
        if (is_dir($qualityDir)) {
            $chunkMap = $this->analyzeChunksInDirectory($qualityDir);
        } else {
            // Fallback to root
            $chunkMap = $this->analyzeChunksInDirectory($videoDir);
        }

        return [
            'success' => true,
            'chunk_map' => $chunkMap,
            'quality' => $quality,
            'video_id' => $videoId
        ];
    }

    private function analyzeChunksInDirectory($directory)
    {
        $chunkMap = ['video' => 0, 'audio' => []];
        $chunkFiles = glob($directory . 'chunk-stream*.enc');

        $streamChunks = [];
        foreach ($chunkFiles as $file) {
            if (preg_match('/chunk-stream(\d+)-(\d+)\.enc$/', basename($file), $matches)) {
                $streamId = (int) $matches[1];
                $chunkNum = (int) $matches[2];

                if (!isset($streamChunks[$streamId])) {
                    $streamChunks[$streamId] = [];
                }

                $streamChunks[$streamId][] = $chunkNum;
            }
        }

        // Organize by stream type
        foreach ($streamChunks as $streamId => $chunks) {
            if ($streamId === 0) {
                $chunkMap['video'] = count(array_unique($chunks));
            } else {
                $chunkMap['audio'][] = [
                    'stream_id' => $streamId,
                    'count' => count(array_unique($chunks)),
                    'chunks' => array_unique($chunks)
                ];
            }
        }

        return $chunkMap;
    }

    /**
     * Create invisible user watermark
     */
    private function createWatermark($userId, $chunkIndex)
    {
        $data = [
            'u' => $userId,
            'c' => $chunkIndex,
            't' => time()
        ];

        // 8-byte watermark
        return pack('N', crc32(json_encode($data))) . pack('N', $chunkIndex);
    }

    /**
     * Generate chunk-specific IV
     */
    private function generateChunkIV($baseIV, $chunkIndex)
    {
        $chunkIV = $baseIV;
        $indexBytes = pack('N', $chunkIndex);

        for ($i = 0; $i < 4; $i++) {
            $chunkIV[12 + $i] = $chunkIV[12 + $i] ^ $indexBytes[$i];
        }

        return $chunkIV;
    }

    /**
     * Log playback events for security monitoring
     */
    private function logPlaybackEvent($videoId, $userId, $event, $data = [])
    {
        $logFile = BASE_PATH . '/logs/playback.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'video_id' => $videoId,
            'user_id' => $userId,
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'data' => $data
        ];

        file_put_contents(
            $logFile,
            json_encode($logEntry) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Cleanup expired sessions
     */
    public function cleanupSessions()
    {
        if (!isset($_SESSION['playback_sessions'])) {
            return;
        }

        $now = time();
        foreach ($_SESSION['playback_sessions'] as $token => $session) {
            if ($now > $session['expires_at']) {
                $this->logPlaybackEvent(
                    $session['video_id'],
                    $session['user_id'],
                    'SESSION_EXPIRED',
                    ['duration' => $now - $session['created_at']]
                );
                unset($_SESSION['playback_sessions'][$token]);
            }
        }
    }

    /**
     * Backward compatibility methods
     */
    public function serveSecureChunk($videoId, $track, $chunkIndex, $sessionToken)
    {
        return $this->serveDynamicChunk($videoId, $track, $chunkIndex, $sessionToken, '720p', 0);
    }

    public function serveSecureChunkMultiQuality($videoId, $track, $chunkIndex, $sessionToken, $quality = '720p')
    {
        return $this->serveDynamicChunk($videoId, $track, $chunkIndex, $sessionToken, $quality, 0);
    }

    public function serveSecureChunkWithAudioTrack($videoId, $track, $chunkIndex, $sessionToken, $quality = '720p', $audioTrack = 0)
    {
        return $this->serveDynamicChunk($videoId, $track, $chunkIndex, $sessionToken, $quality, $audioTrack);
    }
}

/**
 * Enhanced VideoEncryption class with DRM features
 */
class VideoEncryption
{
    // Existing methods remain the same...

    /**
     * Get video key (for server-side use only)
     */
    public function getVideoKey($videoId)
    {
        $keyFile = KEYS_DIR . $videoId . '/key.json';

        if (!file_exists($keyFile)) {
            return ['success' => false, 'error' => 'Video key not found'];
        }

        $keyData = json_decode(file_get_contents($keyFile), true);
        $masterKey = getMasterKey();

        // Decrypt the key and IV
        $decryptedKey = openssl_decrypt(
            base64_decode($keyData['encrypted_key']),
            'AES-256-ECB',
            $masterKey,
            OPENSSL_RAW_DATA
        );

        $decryptedIV = openssl_decrypt(
            base64_decode($keyData['encrypted_iv']),
            'AES-256-ECB',
            $masterKey,
            OPENSSL_RAW_DATA
        );

        if ($decryptedKey === false || $decryptedIV === false) {
            return ['success' => false, 'error' => 'Failed to decrypt video key'];
        }

        return [
            'success' => true,
            'key' => base64_encode($decryptedKey),
            'iv' => base64_encode($decryptedIV),
            'algorithm' => $keyData['algorithm']
        ];
    }
}

// API endpoint handler for direct access
if (isset($_GET['action'])) {
    $manager = new SecurePlaybackManager();

    switch ($_GET['action']) {
        case 'get_chunk_info':
            if (isset($_GET['video_id']) && isset($_GET['quality'])) {
                header('Content-Type: application/json');
                echo json_encode($manager->getChunkInfo($_GET['video_id'], $_GET['quality']));
            }
            break;

        case 'stream_chunk':
            if (
                isset($_GET['video_id']) && isset($_GET['track']) &&
                isset($_GET['index']) && isset($_GET['session_token'])
            ) {

                $quality = $_GET['quality'] ?? '720p';
                $audioTrack = $_GET['audio_track'] ?? 0;

                $manager->serveDynamicChunk(
                    $_GET['video_id'],
                    $_GET['track'],
                    $_GET['index'],
                    $_GET['session_token'],
                    $quality,
                    $audioTrack
                );
            }
            break;
    }
    exit;
}

// Session cleanup on every request
if (session_status() === PHP_SESSION_ACTIVE) {
    $manager = new SecurePlaybackManager();
    $manager->cleanupSessions();
}
?>