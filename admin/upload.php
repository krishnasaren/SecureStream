<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$auth = new Auth();

// Check authentication and admin role
if (!$auth->getCurrentUser() || !$auth->isAdmin()) {
    header('Location: ../pages/login.php');
    exit;
}
$date = new DateTime();
$timestamp = $date->getTimestamp();
$message = '';
$messageType = '';

$videoId='video_' . $timestamp;
$description='';
$title ='';
/*
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $videoId = trim($_POST['video_id'] ?? '');

    // Check if file was uploaded
    if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
        $message = 'No video file uploaded';
        $messageType = 'error';
    } else {
        // Validate file type
        $allowedTypes = ['video/mp4', 'video/mpeg'];
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $fileType = finfo_file($fileInfo, $_FILES['video_file']['tmp_name']);
        finfo_close($fileInfo);

        if (!in_array($fileType, $allowedTypes)) {
            $message = 'Invalid file type. Only MP4 files are allowed.';
            $messageType = 'error';
        } else {
            // Create temporary file
            $tempDir = dirname(__DIR__) . '/uploads/';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempFile = $tempDir . 'upload_' . time() . '_' . bin2hex(random_bytes(8)) . '.mp4';

            if (move_uploaded_file($_FILES['video_file']['tmp_name'], $tempFile)) {
                // Process via API
                $apiUrl = '../api/process_video.php';
                $postData = [
                    'title' => $title,
                    'description' => $description,
                    'video_id' => $videoId
                ];

                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, array_merge($postData, [
                    'video_file' => new CURLFile($tempFile, $fileType, $_FILES['video_file']['name'])
                ]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'X-Requested-With: XMLHttpRequest'
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                // Clean up temp file
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }

                if ($httpCode === 200) {
                    $result = json_decode($response, true);

                    if ($result['success']) {
                        $message = "✅ Video uploaded and encrypted successfully!<br>Video ID: " . $result['video_id'];
                        $messageType = 'success';

                        // Clear form
                        $title = $description = $videoId = '';
                    } else {
                        $message = "❌ " . ($result['error'] ?? 'Processing failed');
                        $messageType = 'error';
                    }
                } else {
                    $message = '❌ Server error: HTTP ' . $httpCode;
                    $messageType = 'error';
                }
            } else {
                $message = '❌ Failed to save uploaded file';
                $messageType = 'error';
            }
        }
    }
}
*/
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Video | SecureStream Admin</title>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --dark: #0f1419;
            --light: #ffffff;
            --gray: #8a94a6;
            --card-bg: #1e2736;
            --success: #10b981;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--dark);
            color: var(--light);
            min-height: 100vh;
        }

        /* Navigation */
        .navbar {
            background: rgba(15, 20, 25, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            gap: 20px;
        }

        .nav-links a {
            color: var(--gray);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.3s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        /* Upload Container */
        .upload-container {
            max-width: 800px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .upload-card {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .upload-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .upload-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .upload-header p {
            color: var(--gray);
            font-size: 16px;
        }

        /* Form */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--light);
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            color: var(--light);
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .file-upload {
            position: relative;
            border: 2px dashed rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .file-upload:hover {
            border-color: var(--primary);
            background: rgba(102, 126, 234, 0.05);
        }

        .file-upload input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .file-upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--gray);
        }

        .file-upload-text {
            color: var(--gray);
            font-size: 16px;
        }

        .file-upload-hint {
            color: var(--gray);
            font-size: 14px;
            margin-top: 10px;
        }

        .upload-btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .upload-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* Message */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            text-align: center;
            font-size: 14px;
        }

        .message.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .message.warning {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Progress Bar */
        .progress-container {
            margin: 25px 0;
            display: none;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            color: var(--gray);
            font-size: 14px;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            width: 0%;
            transition: width 0.3s;
            border-radius: 3px;
        }

        /* Back Link */
        .back-link {
            display: inline-block;
            margin-top: 30px;
            color: var(--gray);
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: var(--primary);
        }

        /* Processing Info */
        .processing-info {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
            font-size: 14px;
            color: var(--gray);
        }

        .processing-info h4 {
            color: var(--light);
            margin-bottom: 10px;
        }

        .processing-info ul {
            list-style: none;
            padding-left: 0;
        }

        .processing-info li {
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
        }

        .processing-info li:before {
            content: "•";
            color: var(--primary);
            position: absolute;
            left: 0;
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 15px;
            }

            .upload-container {
                margin: 20px auto;
                padding: 0 15px;
            }

            .upload-card {
                padding: 25px;
            }

            .upload-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="logo">🔐 SecureStream Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="upload.php" class="active">Upload Video</a>
            <a href="../pages/dashboard.php">User View</a>
            <a href="../pages/dashboard.php?logout">Logout</a>
        </div>
    </nav>

    <!-- Upload Container -->
    <div class="upload-container">
        <div class="upload-card">
            <div class="upload-header">
                <h1>Upload & Encrypt Video</h1>
                <p>Upload MP4 video to be encrypted with AES-256 and split into secure chunks</p>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <!--<form id="upload-form" method="POST" enctype="multipart/form-data">-->

            <form id="upload-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Video Title *</label>
                    <input type="text" id="title" name="title" required placeholder="Enter video title"
                        value="<?php echo htmlspecialchars($title); ?>">
                    <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                </div>

                <div class="form-group">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description"
                        placeholder="Enter video description"><?php echo htmlspecialchars($description); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="video_id">Custom Video ID (Optional)</label>
                    <input type="text" id="video_id" name="video_id" placeholder="Leave empty for auto-generated ID"
                        value="<?php echo htmlspecialchars($videoId); ?>" pattern="[a-zA-Z0-9_\-]+"
                        title="Letters, numbers, underscore, and dash only">
                </div>

                <div class="form-group">
                    <label>Video File *</label>
                    <div class="file-upload" id="file-upload-area">
                        <div class="file-upload-icon">📁</div>
                        <div class="file-upload-text" id="file-name">Click to select MP4 video file</div>
                        <div class="file-upload-hint">Max file size: 2GB</div>
                        <input type="file" id="video_file" name="video_file" accept="video/mp4,video/mpeg,video/quicktime,video/x-matroska,video/webm" required>
                    </div>
                </div>

                <!-- Progress Bar -->
                <div class="progress-container" id="progress-container">
                    <div class="progress-label">
                        <span id="progress-text">Processing...</span>
                        <span id="progress-percent">0%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress-fill"></div>
                    </div>
                </div>

                <button type="submit" class="upload-btn" id="upload-btn">
                    Start Encryption Process
                </button>
            </form>

            <a href="index.php" class="back-link">← Back to Admin Dashboard</a>

            <div class="processing-info">
                <h4>What happens after upload:</h4>
                <ul>
                    <li>Video is split into 10-second chunks</li>
                    <li>Each chunk is encrypted with AES-256-CTR</li>
                    <li>Unique encryption key generated for this video</li>
                    <li>Thumbnail is created from video</li>
                    <li>Original file is moved to secure storage</li>
                    <li>Processing may take several minutes for large videos</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // File upload UI
        const fileInput = document.getElementById('video_file');
        const videoTitle = document.getElementById('title');
        const videoDes = document.getElementById('description');
        const fileName = document.getElementById('file-name');
        const fileUploadArea = document.getElementById('file-upload-area');
        const uploadForm = document.getElementById('upload-form');
        const uploadBtn = document.getElementById('upload-btn');
        const progressContainer = document.getElementById('progress-container');
        const progressFill = document.getElementById('progress-fill');
        const progressPercent = document.getElementById('progress-percent');
        const progressText = document.getElementById('progress-text');

        // File selection handler
        fileInput.addEventListener('change', function () {
            if (this.files.length > 0) {
                const file = this.files[0];
                fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
                fileUploadArea.style.borderColor = '#667eea';
                fileUploadArea.style.background = 'rgba(102, 126, 234, 0.05)';
                //#0f1419
                videoTitle.value = file.name;
                videoDes.value = 'Type: ' + file.type + ', Size: ' + formatFileSize(file.size)+', Last Modified: '+new Date(file.lastModified).toISOString();
            } else {
                fileName.textContent = 'Click to select MP4 video file';
                fileUploadArea.style.borderColor = 'rgba(255,255,255,0.2)';
                fileUploadArea.style.background = 'transparent';
            }
        });

        // Drag and drop
        fileUploadArea.addEventListener('dragover', function (e) {
            e.preventDefault();
            this.style.borderColor = '#667eea';
            this.style.background = 'rgba(102, 126, 234, 0.1)';
        });

        fileUploadArea.addEventListener('dragleave', function (e) {
            e.preventDefault();
            this.style.borderColor = 'rgba(255,255,255,0.2)';
            this.style.background = 'transparent';
        });

        fileUploadArea.addEventListener('drop', function (e) {
            e.preventDefault();
            this.style.borderColor = '#667eea';
            this.style.background = 'rgba(102, 126, 234, 0.05)';

            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                const file = e.dataTransfer.files[0];
                fileName.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
                videoTitle.value = file.name;
                videoDes.value = 'Type: ' + file.type + ', Size: ' + formatFileSize(file.size)+', Last Modified: '+new Date(file.lastModified).toISOString();
            }
        });

        // Form submission with progress
        uploadForm.addEventListener('submit', function (e) {
            e.preventDefault();
            

            // Validate file size (2GB max)
            const file = fileInput.files[0];

            if (!file) {
                alert('Please select a video file');
                return;
            }
            if (file && file.size > 2 * 1024 * 1024 * 1024) {
                alert('File size exceeds 2GB limit');
                return;
            }

            // Show progress
            uploadBtn.disabled = true;
            uploadBtn.textContent = 'Encrypting...';
            progressContainer.style.display = 'block';
            progressText.textContent = 'Starting encryption process...';

            // Simulate progress updates (will be replaced with real progress)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 5;
                if (progress <= 90) {
                    updateProgress(progress, 'Encrypting video chunks...');
                }
            }, 500);

            // Submit form via AJAX
            const formData = new FormData(this);
            console.log(formData);
            console.log([...formData.entries()]);

            fetch('../api/process_video.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    clearInterval(progressInterval);

                    if (data.success) {
                        updateProgress(100, 'Encryption complete!');
                        setTimeout(() => {
                            // Show success message and reload
                            alert('✅ Video uploaded and encrypted successfully!\nVideo ID: ' + data.video_id);
                            location.reload();
                        }, 1000);
                    } else {
                        updateProgress(0, 'Error: ' + (data.error || 'Processing failed'));
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = 'Start Encryption Process';
                        alert('❌ ' + (data.error || 'Processing failed'));
                    }
                })
                .catch(error => {
                    clearInterval(progressInterval);
                    updateProgress(0, 'Upload failed');
                    uploadBtn.disabled = false;
                    uploadBtn.textContent = 'Start Encryption Process';
                    alert('❌ Upload failed: ' + error.message);
                });
        });

        function updateProgress(percent, text) {
            progressFill.style.width = percent + '%';
            progressPercent.textContent = percent + '%';
            progressText.textContent = text;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Auto-generate video ID from title
        document.getElementById('title').addEventListener('input', function () {
            const videoIdField = document.getElementById('video_id');
            if (!videoIdField.value) {
                const title = this.value.toLowerCase()
                    .replace(/[^a-z0-9]+/g, '_')
                    .replace(/^_+|_+$/g, '');
                videoIdField.value = title.substring(0, 50);
            }
        });
    </script>
</body>

</html>