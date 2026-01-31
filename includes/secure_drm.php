<?php
/**
 * ============================================
 * SECURE DRM-LIKE VIDEO STREAMING SYSTEM
 * ============================================
 * 
 * Security Features:
 * - Ephemeral per-chunk encryption keys
 * - Session-bound playback tokens
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
    private $maxRequestsPerMinute = 1000; // old 120 Rate limiting

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

        // Optional: clean empty container
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

        // Check IP binding (optional, can be disabled for mobile users)
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
            // Warning but allow (mobile networks change IPs)
        }

        // Rate limiting
        if ($session['request_count'] > $this->maxRequestsPerMinute) {
            $timeSinceLastRequest = time() - $session['last_request_time'];
            if ($timeSinceLastRequest < 60) {
                //DRM systems never kill playback on chunk bursts — only log.
                //only log
                return ['valid' => false, 'error' => 'Rate limit exceeded'];
                //return ['valid' => true, 'session' => $session];
            }
            // Reset counter after 1 minute
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
     * This is the CORE SECURITY MECHANISM
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
            32, // Key length
            $context,
            'EPHEMERAL_CHUNK_KEY_V1'
        );

        // Generate unique IV for this chunk
        $ephemeralIV = hash_hkdf(
            'sha256',
            $masterKey,
            16, // IV length
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
     * Serve encrypted chunk with double encryption
     */
    public function serveSecureChunk($videoId, $track, $chunkIndex, $sessionToken)
    {
        // Validate session
        $validation = $this->validateSession($sessionToken);
        if (!$validation['valid']) {
            http_response_code(401);
            echo json_encode(['error' => $validation['error']]);
            exit;
        }

        $session = $validation['session'];

        // Sequential chunk validation (prevent jumping ahead)
        if ($chunkIndex > $session['last_chunk'] + 5) {
            $this->logPlaybackEvent(
                $videoId,
                $session['user_id'],
                'SUSPICIOUS_JUMP',
                ['from' => $session['last_chunk'], 'to' => $chunkIndex]
            );
        }

        // Update last chunk
        $_SESSION['playback_sessions'][$sessionToken]['last_chunk'] = max(
            $session['last_chunk'],
            $chunkIndex
        );

        // Map track to stream ID
        $streamId = ($track === 'video') ? 0 : 1;

        // Load encrypted chunk
        $chunkFile = sprintf(
            '%s%s/chunk-stream%d-%05d.enc',
            ENCRYPTED_DIR,
            $videoId,
            $streamId,
            $chunkIndex + 1
        );

        if (!file_exists($chunkFile)) {
            http_response_code(404);
            exit;
        }

        // Read encrypted data
        $encryptedData = file_get_contents($chunkFile);

        // Get video's master key
        $encryption = new VideoEncryption();
        $keyData = $encryption->getVideoKey($videoId);

        if (!$keyData['success']) {
            http_response_code(500);
            exit;
        }

        // Decrypt with master key
        $videoKey = base64_decode($keyData['key']);
        $videoIV = base64_decode($keyData['iv']);

        // Generate chunk-specific IV
        $chunkIV = $this->generateChunkIV($videoIV, $chunkIndex);

        // Decrypt chunk
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

        // Generate ephemeral key for client
        $ephemeralKeyData = $this->generateEphemeralKey($videoId, $chunkIndex, $sessionToken);

        if (!$ephemeralKeyData['success']) {
            http_response_code(403);
            exit;
        }

        $ephemeralKey = base64_decode($ephemeralKeyData['key']);
        $ephemeralIV = base64_decode($ephemeralKeyData['iv']);

        // Re-encrypt with ephemeral key
        $reencryptedData = openssl_encrypt(
            $decryptedData,
            'AES-256-CTR',
            $ephemeralKey,
            OPENSSL_RAW_DATA,
            $ephemeralIV
        );

        // Add user watermark (invisible metadata)
        $watermark = $this->createWatermark($session['user_id'], $chunkIndex);
        $reencryptedData = $watermark . $reencryptedData;

        // Log chunk access
        $this->logPlaybackEvent(
            $videoId,
            $session['user_id'],
            'CHUNK_SERVED',
            ['track' => $track, 'index' => $chunkIndex]
        );

        // Send encrypted data
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($reencryptedData));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Chunk-Index: ' . $chunkIndex);
        header('X-Valid-Until: ' . $ephemeralKeyData['valid_until']);

        echo $reencryptedData;
        exit;
    }


    public function serveSecureChunkMultiQuality($videoId, $track, $chunkIndex, $sessionToken, $quality = '720p')
    {
        // Validate session
        $validation = $this->validateSession($sessionToken);
        if (!$validation['valid']) {
            http_response_code(401);
            echo json_encode(['error' => $validation['error']]);
            exit;
        }

        $session = $validation['session'];

        // Sequential chunk validation
        if ($chunkIndex > $session['last_chunk'] + 5) {
            $this->logPlaybackEvent(
                $videoId,
                $session['user_id'],
                'SUSPICIOUS_JUMP',
                ['from' => $session['last_chunk'], 'to' => $chunkIndex]
            );
        }

        // Update last chunk
        $_SESSION['playback_sessions'][$sessionToken]['last_chunk'] = max(
            $session['last_chunk'],
            $chunkIndex
        );

        // Map track to stream ID
        $streamId = ($track === 'video') ? 0 : 1;

        // Build path with quality folder
        $chunkFile = sprintf(
            '%s%s/%s/chunk-stream%d-%05d.enc',
            ENCRYPTED_DIR,
            $videoId,
            $quality,
            $streamId,
            $chunkIndex + 1
        );

        // Fallback to root folder if quality folder doesn't exist
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

        // Read encrypted data
        $encryptedData = file_get_contents($chunkFile);

        // Get video's master key
        $encryption = new VideoEncryption();
        $keyData = $encryption->getVideoKey($videoId);

        if (!$keyData['success']) {
            http_response_code(500);
            exit;
        }

        // Decrypt with master key
        $videoKey = base64_decode($keyData['key']);
        $videoIV = base64_decode($keyData['iv']);

        // Generate chunk-specific IV
        $chunkIV = $this->generateChunkIV($videoIV, $chunkIndex);

        // Decrypt chunk
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

        // Generate ephemeral key for client
        $ephemeralKeyData = $this->generateEphemeralKey($videoId, $chunkIndex, $sessionToken);

        if (!$ephemeralKeyData['success']) {
            http_response_code(403);
            exit;
        }

        $ephemeralKey = base64_decode($ephemeralKeyData['key']);
        $ephemeralIV = base64_decode($ephemeralKeyData['iv']);

        // Re-encrypt with ephemeral key
        $reencryptedData = openssl_encrypt(
            $decryptedData,
            'AES-256-CTR',
            $ephemeralKey,
            OPENSSL_RAW_DATA,
            $ephemeralIV
        );

        // Add user watermark
        $watermark = $this->createWatermark($session['user_id'], $chunkIndex);
        $reencryptedData = $watermark . $reencryptedData;

        // Log chunk access
        $this->logPlaybackEvent(
            $videoId,
            $session['user_id'],
            'CHUNK_SERVED',
            ['track' => $track, 'index' => $chunkIndex, 'quality' => $quality]
        );

        // Send encrypted data
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($reencryptedData));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Chunk-Index: ' . $chunkIndex);
        header('X-Quality: ' . $quality);
        header('X-Valid-Until: ' . $ephemeralKeyData['valid_until']);

        echo $reencryptedData;
        exit;
    }



    public function serveSecureChunkWithAudioTrack($videoId, $track, $chunkIndex, $sessionToken, $quality = '720p', $audioTrack = 0)
    {
        // Validate session
        $validation = $this->validateSession($sessionToken);
        if (!$validation['valid']) {
            http_response_code(401);
            echo json_encode(['error' => $validation['error']]);
            exit;
        }

        $session = $validation['session'];

        // Sequential chunk validation
        if ($chunkIndex > $session['last_chunk'] + 5) {
            $this->logPlaybackEvent(
                $videoId,
                $session['user_id'],
                'SUSPICIOUS_JUMP',
                ['from' => $session['last_chunk'], 'to' => $chunkIndex]
            );
        }

        // Update last chunk
        $_SESSION['playback_sessions'][$sessionToken]['last_chunk'] = max(
            $session['last_chunk'],
            $chunkIndex
        );

        // Determine stream ID based on track type and audio track selection
        $streamId = 0;

        if ($track === 'video') {
            $streamId = 0; // Video is always stream 0
        } else {
            // Audio streams: stream1 = audio_track_0, stream2 = audio_track_1, etc.
            $streamId = 1 + $audioTrack;
        }

        // Build path with quality folder and correct stream ID
        $chunkFile = sprintf(
            '%s%s/%s/chunk-stream%d-%05d.enc',
            ENCRYPTED_DIR,
            $videoId,
            $quality,
            $streamId,
            $chunkIndex + 1
        );

        // Fallback to root folder if quality folder doesn't exist
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
            // Log missing chunk for debugging
            $this->logPlaybackEvent(
                $videoId,
                $session['user_id'],
                'CHUNK_NOT_FOUND',
                [
                    'track' => $track,
                    'index' => $chunkIndex,
                    'quality' => $quality,
                    'audio_track' => $audioTrack,
                    'stream_id' => $streamId,
                    'attempted_path' => basename($chunkFile)
                ]
            );

            http_response_code(404);
            exit;
        }

        // Read encrypted data
        $encryptedData = file_get_contents($chunkFile);

        // Get video's master key
        $encryption = new VideoEncryption();
        $keyData = $encryption->getVideoKey($videoId);

        if (!$keyData['success']) {
            http_response_code(500);
            exit;
        }

        // Decrypt with master key
        $videoKey = base64_decode($keyData['key']);
        $videoIV = base64_decode($keyData['iv']);

        // Generate chunk-specific IV
        $chunkIV = $this->generateChunkIV($videoIV, $chunkIndex);

        // Decrypt chunk
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

        // Generate ephemeral key for client
        $ephemeralKeyData = $this->generateEphemeralKey($videoId, $chunkIndex, $sessionToken);

        if (!$ephemeralKeyData['success']) {
            http_response_code(403);
            exit;
        }

        $ephemeralKey = base64_decode($ephemeralKeyData['key']);
        $ephemeralIV = base64_decode($ephemeralKeyData['iv']);

        // Re-encrypt with ephemeral key
        $reencryptedData = openssl_encrypt(
            $decryptedData,
            'AES-256-CTR',
            $ephemeralKey,
            OPENSSL_RAW_DATA,
            $ephemeralIV
        );

        // Add user watermark
        $watermark = $this->createWatermark($session['user_id'], $chunkIndex);
        $reencryptedData = $watermark . $reencryptedData;

        // Log chunk access
        $this->logPlaybackEvent(
            $videoId,
            $session['user_id'],
            'CHUNK_SERVED',
            [
                'track' => $track,
                'index' => $chunkIndex,
                'quality' => $quality,
                'audio_track' => $audioTrack,
                'stream_id' => $streamId
            ]
        );

        // Send encrypted data
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($reencryptedData));
        header('Content-Disposition: attachment; filename="' . basename($chunkFile) . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Chunk-Index: ' . $chunkIndex);
        header('X-Quality: ' . $quality);
        header('X-Audio-Track: ' . $audioTrack);
        header('X-Stream-ID: ' . $streamId);
        header('X-Valid-Until: ' . $ephemeralKeyData['valid_until']);

        echo $reencryptedData;
        exit;
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

// Session cleanup on every request
if (session_status() === PHP_SESSION_ACTIVE) {
    $manager = new SecurePlaybackManager();
    $manager->cleanupSessions();
}