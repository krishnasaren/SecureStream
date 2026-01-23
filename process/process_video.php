<?php
require_once '../includes/config.php';

require_once '../includes/encryption.php';
class VideoProcessor
{
    private $ffmpeg_path;
    private $encryption;

    public function __construct()
    {
        // Update this path based on your system
        $this->ffmpeg_path = 'ffmpeg'; // or '/usr/bin/ffmpeg'
        $this->encryption = new VideoEncryption();
    }

    public function processVideo($input_file, $video_id, $title = '')
    {
        try {
            echo "Starting video processing for: $input_file\n";

            // Validate input file
            if (!file_exists($input_file)) {
                throw new Exception("Input file not found: $input_file");
            }

            // Generate unique video ID if not provided
            if (empty($video_id)) {
                $video_id = 'video_' . time() . '_' . bin2hex(random_bytes(8));
            }

            // Create directories for this video
            $video_enc_path = ENCRYPTED_DIR . $video_id . '/';
            $video_key_path = KEYS_DIR . $video_id . '/';

            if (!is_dir($video_enc_path))
                mkdir($video_enc_path, 0755, true);
            if (!is_dir($video_key_path))
                mkdir($video_key_path, 0755, true);

            // Generate encryption key for this video
            $video_key = random_bytes(32); // AES-256 key
            $video_iv = random_bytes(16);  // Initialization vector

            // Save encryption key (encrypted with master key)
            $this->saveVideoKey($video_id, $video_key, $video_iv, $title);

            // Split video into chunks and encrypt
            $this->splitAndEncryptVideo($input_file, $video_enc_path, $video_key, $video_iv);

            // Generate thumbnail
            $this->generateThumbnail($input_file, $video_id, $title);

            // Create video info file
            $this->createVideoInfo($video_id, $input_file, $title);

            echo "Video processing completed successfully!\n";
            echo "Video ID: $video_id\n";
            echo "Encrypted chunks: $video_enc_path\n";
            echo "Keys saved: $video_key_path\n";

            return $video_id;

        } catch (Exception $e) {
            echo "Error processing video: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function splitAndEncryptVideo($input_file, $output_dir, $key, $iv)
    {
        echo "Splitting and encrypting video...\n";

        // First, split video into chunks using ffmpeg
        $chunk_pattern = $output_dir . 'chunk_%03d.mp4';
        $cmd = escapeshellcmd("{$this->ffmpeg_path} -i " . escapeshellarg($input_file) .
            " -c copy -map 0 -f segment -segment_time 10 -reset_timestamps 1 " .
            escapeshellarg($chunk_pattern));

        exec($cmd . " 2>&1", $output, $return_code);

        if ($return_code !== 0) {
            throw new Exception("FFmpeg chunking failed: " . implode("\n", $output));
        }

        // Encrypt each chunk
        $chunks = glob($output_dir . 'chunk_*.mp4');
        if (empty($chunks)) {
            throw new Exception("No chunks were created");
        }

        echo "Encrypting " . count($chunks) . " chunks...\n";

        foreach ($chunks as $index => $chunk_file) {
            $this->encryptChunk($chunk_file, $key, $iv, $index);
            echo "Encrypted chunk " . ($index + 1) . "/" . count($chunks) . "\n";
        }

        echo "All chunks encrypted successfully\n";
    }

    private function encryptChunk($chunk_file, $key, $iv, $chunk_index)
    {
        $data = file_get_contents($chunk_file);

        // Create unique IV for each chunk
        $chunk_iv = $this->generateChunkIV($iv, $chunk_index);

        // Encrypt the chunk
        $encrypted = openssl_encrypt(
            $data,
            ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $chunk_iv
        );

        // Save encrypted chunk with .enc extension
        $encrypted_file = str_replace('.mp4', '.enc', $chunk_file);
        file_put_contents($encrypted_file, $encrypted);

        // Remove original unencrypted chunk
        unlink($chunk_file);
    }

    private function generateChunkIV($base_iv, $chunk_index)
    {
        // Modify IV for each chunk to ensure uniqueness
        $chunk_iv = $base_iv;
        $index_bytes = pack('N', $chunk_index); // Convert index to 4 bytes

        // XOR the last 4 bytes of IV with chunk index
        for ($i = 0; $i < 4; $i++) {
            $chunk_iv[12 + $i] = $chunk_iv[12 + $i] ^ $index_bytes[$i];
        }

        return $chunk_iv;
    }

    private function saveVideoKey($video_id, $key, $iv, $title)
    {
        $master_key = getMasterKey(); 
        // Encrypt video key with master key
        $encrypted_key = openssl_encrypt(
            $key,
            'AES-256-ECB',
            $master_key,
            OPENSSL_RAW_DATA
        );

        // Encrypt IV with master key
        $encrypted_iv = openssl_encrypt(
            $iv,
            'AES-256-ECB',
            $master_key,
            OPENSSL_RAW_DATA
        );

        // Save encrypted key and IV
        $key_data = [
            'video_id' => $video_id,
            'title' => $title,
            'key' => base64_encode($encrypted_key),
            'iv' => base64_encode($encrypted_iv),
            'created_at' => date('Y-m-d H:i:s')
        ];

        file_put_contents(KEYS_DIR . $video_id . '/key.json', json_encode($key_data, JSON_PRETTY_PRINT));
    }

    private function generateThumbnail($input_file, $video_id, $title)
    {
        echo "Generating thumbnail...\n";

        $thumbnail_file = THUMBNAILS_DIR . $video_id . '.jpg';
        $cmd = escapeshellcmd("{$this->ffmpeg_path} -i " . escapeshellarg($input_file) .
            " -ss 00:00:05 -vframes 1 -vf 'scale=320:180' " . escapeshellarg($thumbnail_file));

        exec($cmd . " 2>&1", $output, $return_code);

        if ($return_code !== 0) {
            echo "Thumbnail generation failed, using placeholder\n";
            // Create a simple colored placeholder
            $this->createPlaceholderThumbnail($thumbnail_file, $title);
        }
    }

    private function createPlaceholderThumbnail($file, $title)
    {
        $width = 320;
        $height = 180;

        // Create image
        $image = imagecreatetruecolor($width, $height);

        // Background color
        $bg_color = imagecolorallocate($image, 20, 20, 20);
        imagefill($image, 0, 0, $bg_color);

        // Text color
        $text_color = imagecolorallocate($image, 255, 255, 255);

        // Add title text
        $font = 5; // Built-in font
        $text = substr($title, 0, 30) ?: 'Video';
        imagestring($image, $font, 10, ($height / 2) - 10, $text, $text_color);
        imagestring($image, $font, 10, ($height / 2) + 10, 'Secure Stream', $text_color);

        // Save image
        imagejpeg($image, $file, 80);
        imagedestroy($image);
    }

    private function createVideoInfo($video_id, $input_file, $title)
    {
        $info = [
            'id' => $video_id,
            'title' => $title ?: basename($input_file, '.mp4'),
            'original_file' => basename($input_file),
            'processed_at' => date('Y-m-d H:i:s'),
            'chunk_count' => count(glob(ENCRYPTED_DIR . $video_id . '/*.enc')),
            'encryption' => ENCRYPTION_METHOD
        ];

        file_put_contents(ENCRYPTED_DIR . $video_id . '/info.json', json_encode($info, JSON_PRETTY_PRINT));
    }
}

// Command line usage
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php process_video.php <input_file> [video_id] [title]\n";
        echo "Example: php process_video.php ../videos/original/movie.mp4 movie_123 'My Movie'\n";
        exit(1);
    }

    $input_file = $argv[1];
    $video_id = $argv[2] ?? '';
    $title = $argv[3] ?? '';

    $processor = new VideoProcessor();
    $result = $processor->processVideo($input_file, $video_id, $title);

    if ($result) {
        echo "\nProcessing complete! Video ID: $result\n";
    } else {
        echo "\nProcessing failed!\n";
        exit(1);
    }
} else {
    // Web interface for processing
    header('Content-Type: application/json');

    if (!isset($_POST['video_path']) || !isAuthenticated()) {
        echo json_encode(['error' => 'Unauthorized or invalid request']);
        exit;
    }

    $input_file = $_POST['video_path'];
    $video_id = $_POST['video_id'] ?? '';
    $title = $_POST['title'] ?? '';

    $processor = new VideoProcessor();
    $result = $processor->processVideo($input_file, $video_id, $title);

    echo json_encode(['success' => $result !== false, 'video_id' => $result]);
}
?>