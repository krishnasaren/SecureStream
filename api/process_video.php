<?php



// DEBUG: Log everything
error_log("=== VIDEO UPLOAD DEBUG ===");
error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("Content Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'Not set'));
error_log("FILES received: " . print_r($_FILES, true));
error_log("POST data: " . print_r($_POST, true));

// Check if it's a multipart request
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === false) {
    error_log("ERROR: Not a multipart request!");
    echo json_encode(['success' => false, 'error' => 'Invalid content type']);
    exit;
}
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

// Check if file was uploaded
if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No video file uploaded']);
    exit;
}

// Validate file type
$allowedTypes = ['video/mp4', 'video/mpeg', 'video/quicktime'];
$fileType = mime_content_type($_FILES['video_file']['tmp_name']);

if (!in_array($fileType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only MP4 files are allowed.']);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = dirname(__DIR__) . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$originalName = $_FILES['video_file']['name'];
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
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