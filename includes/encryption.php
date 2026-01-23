<?php
require_once 'config.php';

class VideoEncryption
{

    public function encryptVideo($videoId, $inputFile, $title = '', $description = '')
    {
        try {
            //echo "Starting encryption for: $inputFile\n";

            // Validate input file
            if (!file_exists($inputFile)) {
                throw new Exception("Input file not found: $inputFile");
            }

            // Generate video ID if not provided
            if (empty($videoId)) {
                $videoId = 'video_' . time() . '_' . bin2hex(random_bytes(4));
            }

            // Create directories
            $videoDir = ENCRYPTED_DIR . $videoId . '/';
            $keyDir = KEYS_DIR . $videoId . '/';

            if (!is_dir($videoDir))
                mkdir($videoDir, 0755, true);
            if (!is_dir($keyDir))
                mkdir($keyDir, 0755, true);

            // Generate encryption key and IV for this video
            $videoKey = random_bytes(32); // AES-256 key
            $videoIV = random_bytes(16);  // Initialization vector

            // Split video into chunks and encrypt
            $chunkCount = $this->splitAndEncrypt($inputFile, $videoDir, $videoKey, $videoIV);

            if ($chunkCount === 0) {
                throw new Exception("No chunks were created");
            }

            // Save encryption key (encrypted with master key)
            $this->saveVideoKey($videoId, $videoKey, $videoIV);

            // Generate thumbnail
            $this->generateThumbnail($inputFile, $videoId);

            // Create video info file
            $this->createVideoInfo($videoId, $inputFile, $title, $description, $chunkCount);

            // Move original file to archive
            $originalName = basename($inputFile);
            $archivePath = ORIGINAL_DIR . $videoId . '_' . $originalName;
            rename($inputFile, $archivePath);

            return [
                'success' => true,
                'video_id' => $videoId,
                'chunks' => $chunkCount,
                'title' => $title ?: basename($inputFile, '.mp4')
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function splitAndEncrypt($inputFile, $outputDir, $key, $iv)
    {
        // 1️⃣ Generate DASH fMP4 (init + media fragments)
        $cmd = sprintf(
            'ffmpeg -y -i %s ' .
            '-map 0:v -map 0:a ' .
            '-c:v copy -c:a copy ' .
            '-f dash ' .
            '-seg_duration %d ' .
            '-use_template 1 ' .
            '-use_timeline 0 ' .
            '%s/out.mpd 2>&1',
            escapeshellarg($inputFile),
            CHUNK_SIZE_SECONDS,
            escapeshellarg(rtrim($outputDir, '/'))
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("FFmpeg DASH failed:\n" . implode("\n", $output));
        }

        // 2️⃣ Leave init segments UNENCRYPTED
        // init-stream0.m4s (video)
        // init-stream1.m4s (audio)

        // 3️⃣ Encrypt ONLY media fragments
        $mediaChunks = glob($outputDir . 'chunk-stream*.m4s');
        sort($mediaChunks);

        if (empty($mediaChunks)) {
            throw new Exception("No DASH media chunks created");
        }

        $segmentIndexes = [];

        foreach ($mediaChunks as $file) {
            // Extract DASH segment number (1-based)
            if (!preg_match('/chunk-stream\d+-(\d+)\.m4s$/', $file, $m)) {
                continue;
            }

            $segmentIndex = intval($m[1]) - 1; // convert to 0-based

            // Same IV for video + audio of same segment
            $this->encryptChunk($file, $key, $iv, $segmentIndex);

            $segmentIndexes[$segmentIndex] = true;
        }

        // ✅ RETURN NUMBER OF TIME SEGMENTS
        return count($segmentIndexes);
    }


    private function encryptChunk($chunkFile, $key, $iv, $chunkIndex)
    {
        // Read plaintext fragment
        $data = file_get_contents($chunkFile);
        if ($data === false) {
            throw new Exception("Failed to read chunk: $chunkFile");
        }

        // Derive per-chunk IV
        $chunkIV = $this->generateChunkIV($iv, $chunkIndex);

        // Encrypt (AES-CTR)
        $encrypted = openssl_encrypt(
            $data,
            ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $chunkIV
        );

        if ($encrypted === false) {
            throw new Exception("Encryption failed for chunk $chunkIndex");
        }

        // 🔥 STRIP ORIGINAL EXTENSION COMPLETELY
        $encryptedFile = preg_replace('/\.[^.]+$/', '', $chunkFile) . '.enc';

        if (file_put_contents($encryptedFile, $encrypted) === false) {
            throw new Exception("Failed to write encrypted chunk: $encryptedFile");
        }

        // Remove plaintext fragment
        unlink($chunkFile);
    }


    private function generateChunkIV($baseIV, $chunkIndex)
    {
        // Clone the IV
        $chunkIV = $baseIV;

        // Modify last 4 bytes with chunk index
        $indexBytes = pack('N', $chunkIndex); // 32-bit integer, big endian

        // XOR the index with IV bytes
        for ($i = 0; $i < 4; $i++) {
            $chunkIV[12 + $i] = $chunkIV[12 + $i] ^ $indexBytes[$i];
        }

        return $chunkIV;
    }

    private function saveVideoKey($videoId, $key, $iv)
    {
        $masterKey = getMasterKey();

        // Encrypt video key with master key
        $encryptedKey = openssl_encrypt(
            $key,
            'AES-256-ECB',
            $masterKey,
            OPENSSL_RAW_DATA
        );

        $encryptedIV = openssl_encrypt(
            $iv,
            'AES-256-ECB',
            $masterKey,
            OPENSSL_RAW_DATA
        );

        $keyData = [
            'video_id' => $videoId,
            'encrypted_key' => base64_encode($encryptedKey),
            'encrypted_iv' => base64_encode($encryptedIV),
            'algorithm' => ENCRYPTION_METHOD,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $keyFile = KEYS_DIR . $videoId . '/key.json';
        if (file_put_contents($keyFile, json_encode($keyData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Failed to save encryption key");
        }
    }

    private function generateThumbnail($inputFile, $videoId)
    {
        $thumbnailFile = THUMBNAILS_DIR . $videoId . '.jpg';

        // Try to get thumbnail at 25% of video
        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) .
            " -ss 00:00:05 -vframes 1 -vf 'scale=320:180:force_original_aspect_ratio=decrease,pad=320:180:(ow-iw)/2:(oh-ih)/2' " .
            escapeshellarg($thumbnailFile) . " 2>&1";

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($thumbnailFile)) {
            // Create placeholder thumbnail
            $this->createPlaceholderThumbnail($thumbnailFile, $videoId);
        }
    }

    private function createPlaceholderThumbnail($file, $videoId)
    {
        $width = 320;
        $height = 180;

        // Create image
        $image = imagecreatetruecolor($width, $height);

        // Background gradient
        for ($i = 0; $i < $height; $i++) {
            $color = imagecolorallocate(
                $image,
                (int)(20 + ($i / $height * 20)),
                (int)(20 + ($i / $height * 20)),
                (int)(40 + ($i / $height * 40))
            );
            imageline($image, 0, $i, $width, $i, $color);
        }

        // Add text
        $textColor = imagecolorallocate($image, 255, 255, 255);
        $font = 5; // Built-in font
        imagestring($image, $font, 10, ($height / 2) - 20, "VIDEO", $textColor);
        imagestring($image, $font, 10, ($height / 2) - 5, substr($videoId, 0, 20), $textColor);
        imagestring($image, $font, 10, ($height / 2) + 10, "SECURE STREAM", $textColor);

        // Save image
        imagejpeg($image, $file, 85);
        imagedestroy($image);
    }

    private function detectCodecs($inputFile)
    {
        $cmd = 'ffprobe -v error ' .
            '-show_entries stream=index,codec_type,codec_name,profile,level ' .
            '-of json ' . escapeshellarg($inputFile);

        $json = shell_exec($cmd);
        $info = json_decode($json, true);

        if (!$info || empty($info['streams'])) {
            return 'video/mp4; codecs="avc1.42E01E"';
        }

        $videoCodec = null;
        $audioCodec = null;

        foreach ($info['streams'] as $s) {
            if ($s['codec_type'] === 'video' && $s['codec_name'] === 'h264') {
                $profile = strtolower($s['profile'] ?? 'baseline');
                $level = intval($s['level'] ?? 30);

                // Profile map
                switch ($profile) {
                    case 'high':
                        $avcProfile = '64';
                        break;
                    case 'main':
                        $avcProfile = '4D';
                        break;
                    default:
                        $avcProfile = '42'; // baseline
                }

                // Level hex
                $avcLevel = strtoupper(str_pad(dechex($level), 2, '0', STR_PAD_LEFT));

                $videoCodec = "avc1.{$avcProfile}00{$avcLevel}";
            }

            if ($s['codec_type'] === 'audio' && $s['codec_name'] === 'aac') {
                $audioCodec = 'mp4a.40.2';
            }
        }

        if ($videoCodec && $audioCodec) {
            return 'video/mp4; codecs="' . $videoCodec . ',' . $audioCodec . '"';
        }

        if ($videoCodec) {
            return 'video/mp4; codecs="' . $videoCodec . '"';
        }

        // final fallback
        return 'video/mp4; codecs="avc1.42E01E"';
    }



    private function createVideoInfo($videoId, $inputFile, $title, $description, $chunkCount)
    {
        $info = [
            'id' => $videoId,
            'title' => $title ?: basename($inputFile, '.mp4'),
            'description' => $description,
            'original_file' => basename($inputFile),
            'codec' => $this->detectCodecs($inputFile),
            'created_at' => date('Y-m-d H:i:s'),
            'chunk_count' => $chunkCount,
            'chunk_size_seconds' => CHUNK_SIZE_SECONDS,
            'encryption' => ENCRYPTION_METHOD,
            'status' => 'encrypted'
        ];

        $infoFile = ENCRYPTED_DIR . $videoId . '/info.json';
        if (file_put_contents($infoFile, json_encode($info, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Failed to create video info file");
        }
    }

    private function timeToSeconds($time)
    {
        $parts = explode(':', $time);
        if (count($parts) !== 3)
            return 0;

        $seconds = 0;
        $seconds += intval($parts[0]) * 3600; // hours
        $seconds += intval($parts[1]) * 60;   // minutes
        $seconds += floatval($parts[2]);      // seconds

        return $seconds;
    }

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

    function deriveEphemeralKey($videoId, $chunkIndex)
    {
        if (
            empty($_SESSION['playback'][$videoId]) ||
            $_SESSION['playback'][$videoId]['expires'] < time()
        ) {
            throw new Exception('Playback session expired');
        }

        return hash_hmac(
            'sha256',
            $videoId . '|' . $chunkIndex . '|' . $_SESSION['playback'][$videoId]['token'],
            getMasterKey(),
            true
        );
    }

}
?>