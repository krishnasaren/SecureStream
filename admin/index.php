<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

$auth = new Auth();

// Check authentication and admin role
if (!$auth->getCurrentUser() || !$auth->isAdmin()) {
    header('Location: ../pages/login.php');
    exit;
}

// Get system info
$videos = getAllVideos();
$videoCount = count($videos);
$userCount = $auth->getUserCount();

// Check FFmpeg
$ffmpegAvailable = false;
$ffmpegVersion = 'Not found';
exec('ffmpeg -version 2>&1', $output, $returnCode);
if ($returnCode === 0 && !empty($output[0])) {
    $ffmpegAvailable = true;
    $ffmpegVersion = substr($output[0], 0, 50) . '...';
}

// Check directory permissions
$directories = [
    'videos/' => is_writable(VIDEOS_DIR),
    'videos/encrypted/' => is_writable(ENCRYPTED_DIR),
    'videos/keys/' => is_writable(KEYS_DIR),
    'videos/thumbnails/' => is_writable(THUMBNAILS_DIR),
    'users/' => is_writable(dirname(USERS_FILE)),
];

// Count chunks
$totalChunks = 0;
foreach ($videos as $video) {
    $totalChunks += $video['chunks'];
}

// Recent videos (last 5)
$recentVideos = array_slice($videos, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | SecureStream</title>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --dark: #0f1419;
            --light: #ffffff;
            --gray: #8a94a6;
            --card-bg: #1e2736;
            --success: #10b981;
            --warning: #f59e0b;
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
            align-items: center;
        }

        .nav-links a {
            color: var(--gray);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.3s;
            display: flex;
            /* 🔑 important */
            align-items: center;
            /* vertical centering */
            justify-content: center;

        }

        .nav-links a:hover,
        .nav-links a.active {
            background: rgba(102, 126, 234, 0.1);
            color: var(--primary);
        }

        /* Main Content */
        .admin-container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .welcome-section {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 40px;
        }

        .welcome-section h1 {
            font-size: 36px;
            margin-bottom: 10px;
        }

        .welcome-section p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 18px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .stat-card h3 {
            color: var(--gray);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-sub {
            color: var(--gray);
            font-size: 14px;
            margin-top: 5px;
        }

        /* System Status */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .status-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .status-card h3 {
            color: var(--light);
            font-size: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .status-item:last-child {
            border-bottom: none;
        }

        .status-label {
            color: var(--gray);
        }

        .status-value {
            color: var(--light);
            font-weight: 500;
        }

        .status-good {
            color: var(--success);
        }

        .status-warning {
            color: var(--warning);
        }

        .status-bad {
            color: var(--danger);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .action-btn {
            background: var(--card-bg);
            border: 2px solid rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            color: var(--light);
        }

        .action-btn:hover {
            border-color: var(--primary);
            transform: translateY(-5px);
        }

        .action-btn .icon {
            font-size: 32px;
            margin-bottom: 15px;
            display: block;
        }

        /* Recent Videos */
        .recent-videos {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 40px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            text-align: left;
            padding: 15px;
            color: var(--gray);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            font-weight: 500;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);


        }

        .action-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .action-group .btn {
            white-space: nowrap;
        }


        .table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 15px;
            }

            .admin-container {
                padding: 15px;
            }

            .welcome-section {
                padding: 30px 20px;
            }

            .welcome-section h1 {
                font-size: 28px;
            }

            .table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="logo">🔐 SecureStream Admin</div>
        <div class="nav-links">
            <a href="index.php" class="active">Dashboard</a>
            <a href="upload.php">Upload Video</a>
            <a href="../pages/dashboard.php">User View</a>
            <a href="../pages/dashboard.php?logout">Logout</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="admin-container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1>Admin Dashboard</h1>
            <p>Manage your encrypted video streaming platform</p>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Videos</h3>
                <div class="stat-number">
                    <?php echo $videoCount; ?>
                </div>
                <div class="stat-sub">
                    <?php echo $totalChunks; ?> encrypted chunks
                </div>
            </div>

            <div class="stat-card">
                <h3>Total Users</h3>
                <div class="stat-number">
                    <?php echo $userCount; ?>
                </div>
                <div class="stat-sub">Active sessions</div>
            </div>

            <div class="stat-card">
                <h3>Storage Used</h3>
                <div class="stat-number">
                    <?php echo round($totalChunks * 10 / 1024, 2); ?> MB
                </div>
                <div class="stat-sub">Estimated video storage</div>
            </div>

            <div class="stat-card">
                <h3>Security Level</h3>
                <div class="stat-number">AES-256</div>
                <div class="stat-sub">Military grade encryption</div>
            </div>
        </div>

        <!-- System Status -->
        <div class="status-grid">
            <div class="status-card">
                <h3>🔧 System Status</h3>
                <div class="status-item">
                    <span class="status-label">FFmpeg</span>
                    <span class="status-value <?php echo $ffmpegAvailable ? 'status-good' : 'status-bad'; ?>">
                        <?php echo $ffmpegAvailable ? 'Available' : 'Not Found'; ?>
                    </span>
                </div>
                <div class="status-item">
                    <span class="status-label">PHP Version</span>
                    <span class="status-value status-good">
                        <?php echo phpversion(); ?>
                    </span>
                </div>
                <div class="status-item">
                    <span class="status-label">OpenSSL</span>
                    <span class="status-value status-good">
                        <?php echo OPENSSL_VERSION_TEXT; ?>
                    </span>
                </div>
            </div>

            <div class="status-card">
                <h3>📁 Directory Permissions</h3>
                <?php foreach ($directories as $dir => $writable): ?>
                    <div class="status-item">
                        <span class="status-label">
                            <?php echo $dir; ?>
                        </span>
                        <span class="status-value <?php echo $writable ? 'status-good' : 'status-bad'; ?>">
                            <?php echo $writable ? 'Writable' : 'Not Writable'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="upload.php" class="action-btn">
                <span class="icon">📤</span>
                <h3>Upload Video</h3>
                <p>Upload and encrypt new video</p>
            </a>

            <a href="#" class="action-btn" onclick="showVideoList()">
                <span class="icon">📹</span>
                <h3>Manage Videos</h3>
                <p>View all encrypted videos</p>
            </a>

            <a href="#" class="action-btn" onclick="showUsers()">
                <span class="icon">👥</span>
                <h3>User Management</h3>
                <p>Manage user accounts</p>
            </a>

            <a href="#" class="action-btn" onclick="showSystemLogs()">
                <span class="icon">📊</span>
                <h3>System Logs</h3>
                <p>View system activity</p>
            </a>
        </div>

        <!-- Recent Videos -->
        <div class="recent-videos">
            <h3 style="margin-bottom: 20px; color: var(--light);">Recent Videos</h3>
            <?php if (empty($recentVideos)): ?>
                <p style="color: var(--gray); text-align: center; padding: 40px;">No videos uploaded yet</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>ID</th>
                            <th>Chunks</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentVideos as $video): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($video['title']); ?>
                                </td>
                                <td><code><?php echo $video['id']; ?></code></td>
                                <td>
                                    <?php echo $video['chunks']; ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($video['created_at'])); ?>
                                </td>
                                <td>
                                    <div class="action-group">
                                        <form action="../pages/player.php?uuid=<?php echo createTimeBoundToken($video['id'], $_SESSION['user_id'], 10000); ?>"
                                            method="POST">
                                            <input type="hidden" name="id" value="<?php echo $video['id']; ?>">
                                            <button type="submit" class="btn btn-primary">Play</button>
                                        </form>
                                        
                                        <button onclick="deleteVideo('<?php echo $video['id']; ?>')" class="btn btn-danger">Delete</button>
                                    </div>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showVideoList() {
            window.location.href = '../pages/dashboard.php';
        }

        function showUsers() {
            alert('User management feature coming soon!');
        }

        function showSystemLogs() {
            alert('System logs feature coming soon!');
        }
        const csrf_token = <?php echo json_encode($_SESSION['csrf_token']); ?>;

        function deleteVideo(videoId) {
            if (confirm('Are you sure you want to delete this video?\n\nThis will permanently delete all encrypted chunks and keys.')) {
                fetch('../api/delete_video.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'video_id=' + encodeURIComponent(videoId)+'&csrf_token='+encodeURIComponent(csrf_token)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Video deleted successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(error => {
                        alert('Error deleting video: ' + error.message);
                    });
            }
        }

        // Auto-refresh system status every 30 seconds
        setInterval(() => {
            console.log('Admin dashboard refreshed');
        }, 30000);
    </script>
</body>

</html>