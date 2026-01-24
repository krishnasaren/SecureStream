<?php





require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/encryption.php';

header('Content-Type: application/json');

// Initialize auth
$auth = new Auth();

// Check authentication and admin role
if (!$auth->getCurrentUser() || !$auth->isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}


// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get parameters
$title = $_POST['title'] ?? '';
$description = $_POST['description'] ?? '';
$videoId = $_POST['video_id'] ?? '';

if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9_-]{6,64}$/', $videoId)) {
    echo json_encode(['success' => false, 'error' => 'Invalid video ID']);
    exit;
}



// Check if it's a multipart request
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === false) {
    error_log("ERROR: Not a multipart request!");
    echo json_encode(['success' => false, 'error' => 'Invalid content type']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No video file uploaded']);
    exit;
}




// Validate file type
$allowedTypes = ['video/mp4', 'video/mpeg', 'video/quicktime','video/x-matroska','video/webm'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$fileType = $finfo->file($_FILES['video_file']['tmp_name']);

if (!in_array($fileType, $allowedTypes,true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only MP4, WebM, MKV, and MOV files are allowed.']);
    exit;
}
$mimeToExt = [
    'video/mp4' => 'mp4',
    'video/webm' => 'webm',
    'video/x-matroska' => 'mkv',
    'video/quicktime' => 'mov',
    'video/mpeg' => 'mpeg'
];

$extension = $mimeToExt[$fileType];
$allowedExtensions = ['mp4', 'mpeg', 'mpg', 'mov', 'mkv', 'webm'];

if (!in_array($extension, $allowedExtensions, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file Extension']);
    exit;
}

$maxSize =2* 1024 * 1024 * 1024; // 2GB
if ($_FILES['video_file']['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File is too large.']);
    exit;
}


// Create uploads directory if it doesn't exist
$uploadDir = dirname(__DIR__) . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$originalName = $_FILES['video_file']['name'];
$tempFile = $uploadDir . 'temp_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

// Move uploaded file
if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $tempFile)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

// Process the video
$encryption = new VideoEncryption();
$result = $encryption->encryptVideo($videoId, $tempFile, $title, $description);

// Clean up temp file
if (file_exists($tempFile)) {
    unlink($tempFile);
}

echo json_encode($result);
?>