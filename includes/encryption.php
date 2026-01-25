<?php
require_once 'config.php';

class VideoEncryption
{
    // Quality presets
    private $qualityPresets = [
        '360p' => [
            'video_bitrate' => '800k',
            'width' => 640,
            'height' => 360,
            'audio_bitrate' => '96k'
        ],
        '480p' => [
            'video_bitrate' => '1400k',
            'width' => 854,
            'height' => 480,
            'audio_bitrate' => '128k'
        ],
        '720p' => [
            'video_bitrate' => '2800k',
            'width' => 1280,
            'height' => 720,
            'audio_bitrate' => '192k'
        ],
        '1080p' => [
            'video_bitrate' => '5000k',
            'width' => 1920,
            'height' => 1080,
            'audio_bitrate' => '256k'
        ]
    ];

    public function encryptVideo($videoId, $inputFile, $title = '', $description = '')
    {
        try {
            if (!file_exists($inputFile)) {
                throw new Exception("Input file not found: $inputFile");
            }

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

            // Analyze input video
            $videoInfo = $this->analyzeVideo($inputFile);

            // Generate encryption key and IV
            $videoKey = random_bytes(32);
            $videoIV = random_bytes(16);

            // Detect available tracks
            $tracks = $this->detectTracks($inputFile);

            // Process video with multiple qualities and tracks
            $processResult = $this->processMultiQuality(
                $inputFile,
                $videoDir,
                $videoKey,
                $videoIV,
                $videoInfo,
                $tracks
            );

            // Save encryption key
            $this->saveVideoKey($videoId, $videoKey, $videoIV);

            // Generate thumbnail
            $this->generateThumbnail($inputFile, $videoId);

            // Create comprehensive video info
            $this->createVideoInfo(
                $videoId,
                $inputFile,
                $title,
                $description,
                $processResult,
                $tracks
            );

            // Move original to archive
            $originalName = basename($inputFile);
            $archivePath = ORIGINAL_DIR . $videoId . '_' . $originalName;
            rename($inputFile, $archivePath);

            return [
                'success' => true,
                'video_id' => $videoId,
                'qualities' => array_keys($processResult['qualities']),
                'audio_tracks' => count($tracks['audio']),
                'subtitle_tracks' => count($tracks['subtitles']),
                'title' => $title ?: basename($inputFile)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function analyzeVideo($inputFile)
    {
        $cmd = 'ffprobe -v quiet -print_format json -show_format -show_streams ' .
            escapeshellarg($inputFile);

        $output = shell_exec($cmd);
        $data = json_decode($output, true);

        if (!$data) {
            throw new Exception("Failed to analyze video");
        }

        $videoStream = null;
        $audioStreams = [];

        foreach ($data['streams'] as $stream) {
            if ($stream['codec_type'] === 'video' && !$videoStream) {
                $videoStream = $stream;
            } elseif ($stream['codec_type'] === 'audio') {
                $audioStreams[] = $stream;
            }
        }

        return [
            'duration' => $data['format']['duration'] ?? 0,
            'video' => $videoStream,
            'audio' => $audioStreams,
            'format' => $data['format']
        ];
    }

    private function detectTracks($inputFile)
    {
        $cmd = 'ffprobe -v quiet -print_format json -show_streams ' .
            escapeshellarg($inputFile);

        $output = shell_exec($cmd);
        $data = json_decode($output, true);

        $tracks = [
            'video' => [],
            'audio' => [],
            'subtitles' => []
        ];

        foreach ($data['streams'] as $index => $stream) {
            if ($stream['codec_type'] === 'video') {
                $tracks['video'][] = [
                    'index' => $stream['index'],
                    'codec' => $stream['codec_name'],
                    'width' => $stream['width'] ?? 0,
                    'height' => $stream['height'] ?? 0
                ];
            } elseif ($stream['codec_type'] === 'audio') {
                $tracks['audio'][] = [
                    'index' => $stream['index'],
                    'codec' => $stream['codec_name'],
                    'language' => $stream['tags']['language'] ?? 'und',
                    'title' => $stream['tags']['title'] ?? "Audio Track " . (count($tracks['audio']) + 1)
                ];
            } elseif ($stream['codec_type'] === 'subtitle') {
                $tracks['subtitles'][] = [
                    'index' => $stream['index'],
                    'codec' => $stream['codec_name'],
                    'language' => $stream['tags']['language'] ?? 'und',
                    'title' => $stream['tags']['title'] ?? "Subtitle Track " . (count($tracks['subtitles']) + 1)
                ];
            }
        }

        return $tracks;
    }

    private function processMultiQuality($inputFile, $outputDir, $key, $iv, $videoInfo, $tracks)
    {
        // Determine which qualities to generate based on source resolution
        $sourceHeight = $videoInfo['video']['height'] ?? 1080;
        $qualities = $this->selectQualities($sourceHeight);

        $result = [
            'qualities' => [],
            'total_chunks' => 0
        ];

        // Extract and process subtitles first
        $this->extractSubtitles($inputFile, $outputDir, $tracks);

        // Process each quality
        foreach ($qualities as $quality => $preset) {
            //echo "Processing quality: $quality\n";

            $qualityDir = $outputDir . $quality . '/';
            if (!is_dir($qualityDir))
                mkdir($qualityDir, 0755, true);

            // Generate DASH segments for this quality
            $chunkCount = $this->generateDASHSegments(
                $inputFile,
                $qualityDir,
                $preset,
                $tracks['audio'],
                $key,
                $iv
            );

            $result['qualities'][$quality] = [
                'chunks' => $chunkCount,
                'preset' => $preset
            ];

            $result['total_chunks'] = max($result['total_chunks'], $chunkCount);
        }

        // Generate master MPD manifest
        $this->generateMasterMPD($outputDir, $qualities, $tracks, $result['total_chunks']);

        return $result;
    }

    private function selectQualities($sourceHeight)
    {
        $selected = [];

        foreach ($this->qualityPresets as $quality => $preset) {
            if ($preset['height'] <= $sourceHeight) {
                $selected[$quality] = $preset;
            }
        }

        // Always include at least one quality
        if (empty($selected)) {
            $selected['360p'] = $this->qualityPresets['360p'];
        }

        return $selected;
    }

    private function canVideoStreamCopy($inputFile)
    {
        $cmd = 'ffprobe -v error -select_streams v:0 '
            . '-show_entries stream=codec_name,pix_fmt '
            . '-of json ' . escapeshellarg($inputFile);

        $json = shell_exec($cmd);
        $info = json_decode($json, true);

        if (!$info || empty($info['streams'][0])) {
            return false;
        }

        $codec = $info['streams'][0]['codec_name'] ?? '';
        $pixFmt = $info['streams'][0]['pix_fmt'] ?? '';

        if ($codec === 'h264' && $pixFmt === 'yuv420p') {
            return true;
        }

        return false; // HEVC, 10bit, VP9, etc
    }

    private function canAudioStreamCopy($inputFile)
    {
        $cmd = 'ffprobe -v error -select_streams a:0 '
            . '-show_entries stream=codec_name,channels '
            . '-of json ' . escapeshellarg($inputFile);

        $json = shell_exec($cmd);
        $info = json_decode($json, true);

        if (!$info || empty($info['streams'][0])) {
            return false;
        }

        $codec = $info['streams'][0]['codec_name'] ?? '';
        $channels = $info['streams'][0]['channels'] ?? 0;

        return ($codec === 'aac' && $channels <= 2);
    }




    private function generateDASHSegments($inputFile, $outputDir, $preset, $audioTracks, $key, $iv)
    {
        // Build complex FFmpeg command for multi-track DASH
        $videoMap = '-map 0:v:0';
        $audioMaps = [];

        foreach ($audioTracks as $idx => $track) {
            $audioMaps[] = "-map 0:a:$idx";
        }

        $audioMapStr = implode(' ', $audioMaps);

        // Decide copy capability
        $canCopyVideo = $this->canVideoStreamCopy($inputFile);
        $canCopyAudio = $this->canAudioStreamCopy($inputFile);

        // Video args
        if ($canCopyVideo) {
            $videoArgs = '-c:v copy';
            $videoFilter = ''; // IMPORTANT: no scaling when copying
        } else {
            $videoArgs = sprintf(
                '-c:v libx264 -preset ultrafast -b:v %s -maxrate %s -bufsize %s',
                $preset['video_bitrate'],
                $preset['video_bitrate'],
                (intval($preset['video_bitrate']) * 2) . 'k'
            );

            $videoFilter = sprintf(
                '-vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2"',
                $preset['width'],
                $preset['height'],
                $preset['width'],
                $preset['height']
            );
        }

        // Audio args
        if ($canCopyAudio) {
            $audioArgs = '-c:a copy';
        } else {
            $audioArgs = sprintf(
                '-c:a aac -b:a %s -ac 2',
                $preset['audio_bitrate']
            );
        }

        // FINAL command (single build, no overwrite)
        $cmd = sprintf(
            'ffmpeg -y -i %s ' .
            '%s %s ' .
            '%s %s %s ' .
            '-f dash ' .
            '-seg_duration %d ' .
            '-use_template 1 ' .
            '-use_timeline 0 ' .
            '-init_seg_name "init-stream$RepresentationID$.m4s" ' .
            '-media_seg_name "chunk-stream$RepresentationID$-$Number%%05d$.m4s" ' .
            '%s/out.mpd 2>&1',
            escapeshellarg($inputFile),
            $videoMap,
            $audioMapStr,
            $videoArgs,
            $videoFilter,
            $audioArgs,
            CHUNK_SIZE_SECONDS,
            escapeshellarg(rtrim($outputDir, '/'))
        );



        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("FFmpeg DASH failed:\n" . implode("\n", $output));
        }

        // Encrypt all media segments (NOT init segments)
        $mediaChunks = glob($outputDir . 'chunk-stream*.m4s');
        sort($mediaChunks);

        if (empty($mediaChunks)) {
            throw new Exception("No DASH media chunks created for quality");
        }

        $segmentIndexes = [];

        foreach ($mediaChunks as $file) {
            if (!preg_match('/chunk-stream(\d+)-(\d+)\.m4s$/', $file, $m)) {
                continue;
            }

            $streamId = intval($m[1]);
            $segmentIndex = intval($m[2]) - 1; // 0-based

            $this->encryptChunk($file, $key, $iv, $segmentIndex);

            if (!isset($segmentIndexes[$segmentIndex])) {
                $segmentIndexes[$segmentIndex] = true;
            }
        }

        return count($segmentIndexes);
    }

    private function extractSubtitles($inputFile, $outputDir, $tracks)
    {
        if (empty($tracks['subtitles'])) {
            return;
        }

        $subsDir = $outputDir . 'subtitles/';
        if (!is_dir($subsDir))
            mkdir($subsDir, 0755, true);

        foreach ($tracks['subtitles'] as $idx => $track) {
            $outputFile = sprintf(
                '%ssub_%s_%d.vtt',
                $subsDir,
                $track['language'],
                $idx
            );

            $cmd = sprintf(
                'ffmpeg -y -i %s -map 0:s:%d %s 2>&1',
                escapeshellarg($inputFile),
                $track['index'],
                escapeshellarg($outputFile)
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode === 0) {
                $tracks['subtitles'][$idx]['file'] = basename($outputFile);
            }
        }
    }

    private function generateMasterMPD($outputDir, $qualities, $tracks, $totalChunks)
    {
        // Create a master manifest that references all qualities
        $mpd = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $mpd .= '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" ';
        $mpd .= 'mediaPresentationDuration="PT' . ($totalChunks * CHUNK_SIZE_SECONDS) . 'S" ';
        $mpd .= 'minBufferTime="PT2S" profiles="urn:mpeg:dash:profile:isoff-main:2011">' . "\n";
        $mpd .= '  <Period>' . "\n";

        // Video AdaptationSet
        $mpd .= '    <AdaptationSet mimeType="video/mp4" codecs="avc1.64001e" ';
        $mpd .= 'segmentAlignment="true" startWithSAP="1">' . "\n";

        foreach ($qualities as $quality => $preset) {
            $mpd .= sprintf(
                '      <Representation id="%s" bandwidth="%d" width="%d" height="%d">' . "\n",
                $quality,
                intval($preset['video_bitrate']) * 1000,
                $preset['width'],
                $preset['height']
            );
            $mpd .= sprintf('        <BaseURL>%s/</BaseURL>' . "\n", $quality);
            $mpd .= '      </Representation>' . "\n";
        }

        $mpd .= '    </AdaptationSet>' . "\n";

        // Audio AdaptationSets (one per language)
        $audioLangs = [];
        foreach ($tracks['audio'] as $track) {
            $lang = $track['language'];
            if (!isset($audioLangs[$lang])) {
                $audioLangs[$lang] = [];
            }
            $audioLangs[$lang][] = $track;
        }

        foreach ($audioLangs as $lang => $audioTracks) {
            $mpd .= sprintf(
                '    <AdaptationSet mimeType="audio/mp4" codecs="mp4a.40.2" lang="%s">' . "\n",
                $lang
            );

            foreach ($audioTracks as $idx => $track) {
                $mpd .= sprintf(
                    '      <Representation id="audio_%s_%d" bandwidth="128000">' . "\n",
                    $lang,
                    $idx
                );
                $mpd .= '      </Representation>' . "\n";
            }

            $mpd .= '    </AdaptationSet>' . "\n";
        }

        $mpd .= '  </Period>' . "\n";
        $mpd .= '</MPD>';

        file_put_contents($outputDir . 'master.mpd', $mpd);
    }

    private function encryptChunk($chunkFile, $key, $iv, $chunkIndex)
    {
        $data = file_get_contents($chunkFile);
        if ($data === false) {
            throw new Exception("Failed to read chunk: $chunkFile");
        }

        $chunkIV = $this->generateChunkIV($iv, $chunkIndex);

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

        $encryptedFile = preg_replace('/\.[^.]+$/', '', $chunkFile) . '.enc';

        if (file_put_contents($encryptedFile, $encrypted) === false) {
            throw new Exception("Failed to write encrypted chunk: $encryptedFile");
        }

        unlink($chunkFile);
    }

    public function generateChunkIV($baseIV, $chunkIndex)
    {
        $chunkIV = $baseIV;
        $indexBytes = pack('N', $chunkIndex);

        for ($i = 0; $i < 4; $i++) {
            $chunkIV[12 + $i] = $chunkIV[12 + $i] ^ $indexBytes[$i];
        }

        return $chunkIV;
    }

    private function saveVideoKey($videoId, $key, $iv)
    {
        $masterKey = getMasterKey();

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
        file_put_contents($keyFile, json_encode($keyData, JSON_PRETTY_PRINT));
    }

    private function generateThumbnail($inputFile, $videoId)
    {
        $thumbnailFile = THUMBNAILS_DIR . $videoId . '.jpg';

        $cmd = "ffmpeg -i " . escapeshellarg($inputFile) .
            " -ss 00:00:05 -vframes 1 -vf 'scale=320:180:force_original_aspect_ratio=decrease,pad=320:180:(ow-iw)/2:(oh-ih)/2' " .
            escapeshellarg($thumbnailFile) . " 2>&1";

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($thumbnailFile)) {
            $this->createPlaceholderThumbnail($thumbnailFile, $videoId);
        }
    }

    private function createPlaceholderThumbnail($file, $videoId)
    {
        $width = 320;
        $height = 180;

        $image = imagecreatetruecolor($width, $height);

        for ($i = 0; $i < $height; $i++) {
            $color = imagecolorallocate(
                $image,
                (int) (20 + ($i / $height * 20)),
                (int) (20 + ($i / $height * 20)),
                (int) (40 + ($i / $height * 40))
            );
            imageline($image, 0, $i, $width, $i, $color);
        }

        $textColor = imagecolorallocate($image, 255, 255, 255);
        $font = 5;
        imagestring($image, $font, 10, ($height / 2) - 20, "VIDEO", $textColor);
        imagestring($image, $font, 10, ($height / 2) - 5, substr($videoId, 0, 20), $textColor);
        imagestring($image, $font, 10, ($height / 2) + 10, "SECURE STREAM", $textColor);

        imagejpeg($image, $file, 85);
        imagedestroy($image);
    }

    private function createVideoInfo($videoId, $inputFile, $title, $description, $processResult, $tracks)
    {
        $info = [
            'id' => $videoId,
            'title' => $title ?: basename($inputFile),
            'description' => $description,
            'original_file' => basename($inputFile),
            'created_at' => date('Y-m-d H:i:s'),
            'chunk_count' => $processResult['total_chunks'],
            'chunk_size_seconds' => CHUNK_SIZE_SECONDS,
            'encryption' => ENCRYPTION_METHOD,
            'status' => 'encrypted',
            'qualities' => array_keys($processResult['qualities']),
            'audio_tracks' => $tracks['audio'],
            'subtitle_tracks' => $tracks['subtitles'] ?? [],
            'has_multi_quality' => count($processResult['qualities']) > 1,
            'has_multi_audio' => count($tracks['audio']) > 1,
            'has_subtitles' => !empty($tracks['subtitles'])
        ];

        $infoFile = ENCRYPTED_DIR . $videoId . '/info.json';
        file_put_contents($infoFile, json_encode($info, JSON_PRETTY_PRINT));
    }

    public function getVideoKey($videoId)
    {
        $keyFile = KEYS_DIR . $videoId . '/key.json';

        if (!file_exists($keyFile)) {
            return ['success' => false, 'error' => 'Video key not found'];
        }

        $keyData = json_decode(file_get_contents($keyFile), true);
        $masterKey = getMasterKey();

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
