<?php
require_once 'config.php';

class VideoEncryption
{
    private $qualityPresets = [
        '144p' => ['video_bitrate' => '120k', 'width' => 256, 'height' => 144, 'audio_bitrate' => '48k'],
        '240p' => ['video_bitrate' => '300k', 'width' => 426, 'height' => 240, 'audio_bitrate' => '64k'],
        '360p' => ['video_bitrate' => '800k', 'width' => 640, 'height' => 360, 'audio_bitrate' => '96k'],
        '480p' => ['video_bitrate' => '1400k', 'width' => 854, 'height' => 480, 'audio_bitrate' => '128k'],
        '720p' => ['video_bitrate' => '2800k', 'width' => 1280, 'height' => 720, 'audio_bitrate' => '192k'],
        '1080p' => ['video_bitrate' => '5000k', 'width' => 1920, 'height' => 1080, 'audio_bitrate' => '256k']
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

            // Create comprehensive video info with chunk mapping
            $videoInfo = $this->createVideoInfo(
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
                'title' => $title ?: basename($inputFile),
                'chunk_map' => $processResult['chunk_map']
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

    private function processMultiQuality($inputFile, $outputDir, $key, $iv, $videoInfo, &$tracks)
    {
        $sourceHeight = $videoInfo['video']['height'] ?? 1080;
        $qualities = $this->selectQualities($sourceHeight);

        $result = [
            'qualities' => [],
            'chunk_map' => []
        ];

        // Extract and process subtitles first
        $this->extractSubtitles($inputFile, $outputDir, $tracks);

        // Process each quality
        foreach ($qualities as $quality => $preset) {
            $qualityDir = $outputDir . $quality . '/';
            if (!is_dir($qualityDir))
                mkdir($qualityDir, 0755, true);

            // Generate DASH segments for this quality
            $chunkInfo = $this->generateDASHSegments(
                $inputFile,
                $qualityDir,
                $preset,
                $tracks['audio'],
                $videoInfo,
                $key,
                $iv
            );

            $result['qualities'][$quality] = [
                'chunks' => $chunkInfo,
                'preset' => $preset
            ];

            // Store chunk mapping for this quality
            $result['chunk_map'][$quality] = $chunkInfo;
        }

        // Generate master MPD manifest
        $this->generateMasterMPD($outputDir, $qualities, $tracks, $result['chunk_map']);

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

        if (empty($selected)) {
            $selected['360p'] = $this->qualityPresets['360p'];
        }

        return $selected;
    }

    private function generateDASHSegments($inputFile, $outputDir, $preset, $audioTracks, $videoInfo, $key, $iv)
    {
        $videoMap = '-map 0:v:0';
        $audioMaps = [];

        foreach ($audioTracks as $idx => $track) {
            $audioMaps[] = "-map 0:a:$idx";
        }

        $audioMapStr = implode(' ', $audioMaps);

        $codec = $videoInfo['video']['codec_name'] ?? '';
        $pixFmt = $videoInfo['video']['pix_fmt'] ?? '';
        $srcH = $videoInfo['video']['height'] ?? 0;

        $canCopyVideo = ($codec === 'h264' && $pixFmt === 'yuv420p' && abs($preset['height'] - $srcH) <= 8);
        $canCopyAudio = false;

        if (!empty($videoInfo['audio'])) {
            $a = $videoInfo['audio'][0];
            $canCopyAudio = (($a['codec_name'] ?? '') === 'aac') && (($a['channels'] ?? 0) <= 2);
        }

        // Video args
        if ($canCopyVideo) {
            $videoArgs = '-c:v copy';
            $videoFilter = '';
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
            $audioArgs = sprintf('-c:a aac -b:a %s -ac 2', $preset['audio_bitrate']);
        }

        // DASH command
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

        // Analyze generated chunks
        $chunkInfo = $this->analyzeChunks($outputDir, $key, $iv, count($audioTracks));

        return $chunkInfo;
    }

    private function analyzeChunks($outputDir, $key, $iv, $audioTrackCount)
    {
        $chunkInfo = [
            'video' => 0,
            'audio' => []
        ];

        // Find all chunk files
        $allChunks = glob($outputDir . 'chunk-stream*.m4s');

        // Group by stream ID
        $streamChunks = [];
        foreach ($allChunks as $chunk) {
            if (preg_match('/chunk-stream(\d+)-(\d+)\.m4s$/', $chunk, $matches)) {
                $streamId = (int) $matches[1];
                $chunkNum = (int) $matches[2];

                if (!isset($streamChunks[$streamId])) {
                    $streamChunks[$streamId] = [];
                }

                if (!in_array($chunkNum, $streamChunks[$streamId])) {
                    $streamChunks[$streamId][] = $chunkNum;
                }

                // Encrypt the chunk
                $this->encryptChunk($chunk, $key, $iv, $chunkNum - 1);
            }
        }

        // Set video chunks (stream 0)
        if (isset($streamChunks[0])) {
            $chunkInfo['video'] = count($streamChunks[0]);
        }

        // Set audio chunks (streams 1+)
        for ($i = 1; $i <= $audioTrackCount; $i++) {
            if (isset($streamChunks[$i])) {
                $chunkInfo['audio'][$i] = [
                    'count' => count($streamChunks[$i]),
                    'chunks' => $streamChunks[$i],
                    'stream_id' => $i
                ];
            } else {
                // Fallback: try to find any audio stream
                foreach ($streamChunks as $streamId => $chunks) {
                    if ($streamId > 0 && !isset($chunkInfo['audio'][$streamId])) {
                        $chunkInfo['audio'][$streamId] = [
                            'count' => count($chunks),
                            'chunks' => $chunks,
                            'stream_id' => $streamId
                        ];
                        break;
                    }
                }
            }
        }

        return $chunkInfo;
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

    private function extractSubtitles($inputFile, $outputDir, &$tracks)
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
                'ffmpeg -y -i %s -map 0:%d %s 2>&1',
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

    private function generateMasterMPD($outputDir, $qualities, $tracks, $chunkMap)
    {
        // Calculate maximum chunks across all qualities for duration
        $maxChunks = 0;
        foreach ($chunkMap as $quality => $chunkInfo) {
            $qualityMax = $chunkInfo['video'];
            foreach ($chunkInfo['audio'] as $audioInfo) {
                $qualityMax = max($qualityMax, $audioInfo['count']);
            }
            $maxChunks = max($maxChunks, $qualityMax);
        }

        $duration = $maxChunks * CHUNK_SIZE_SECONDS;

        $mpd = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $mpd .= '<MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" ';
        $mpd .= 'mediaPresentationDuration="PT' . $duration . 'S" ';
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

        // Audio AdaptationSets
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
        // Calculate maximum chunks across all qualities
        $maxChunks = 0;
        foreach ($processResult['chunk_map'] as $quality => $chunkInfo) {
            $qualityMax = $chunkInfo['video'];
            foreach ($chunkInfo['audio'] as $audioInfo) {
                $qualityMax = max($qualityMax, $audioInfo['count']);
            }
            $maxChunks = max($maxChunks, $qualityMax);
        }

        $info = [
            'id' => $videoId,
            'title' => $title ?: basename($inputFile),
            'description' => $description,
            'original_file' => basename($inputFile),
            'created_at' => date('Y-m-d H:i:s'),
            'chunk_count' => $maxChunks, // Maximum across all streams
            'chunk_size_seconds' => CHUNK_SIZE_SECONDS,
            'encryption' => ENCRYPTION_METHOD,
            'status' => 'encrypted',
            'qualities' => array_keys($processResult['qualities']),
            'audio_tracks' => $tracks['audio'],
            'subtitle_tracks' => $tracks['subtitles'] ?? [],
            'has_multi_quality' => count($processResult['qualities']) > 1,
            'has_multi_audio' => count($tracks['audio']) > 1,
            'has_subtitles' => !empty($tracks['subtitles']),
            'chunk_map' => $processResult['chunk_map']
        ];

        $infoFile = ENCRYPTED_DIR . $videoId . '/info.json';
        file_put_contents($infoFile, json_encode($info, JSON_PRETTY_PRINT));

        return $info;
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
?>