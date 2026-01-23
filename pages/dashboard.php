<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Initialize auth
$auth = new Auth();

// Check authentication
if (!$auth->getCurrentUser()) {
    header('Location: login.php');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: login.php');
    exit;
}

// Get all videos
$videos = getAllVideos();
$isAdmin = $auth->isAdmin();
$username = $auth->getCurrentUser();
$userCount = $auth->getUserCount();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SecureStream</title>
    <style>
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --dark: #0f1419;
            --light: #ffffff;
            --gray: #8a94a6;
            --card-bg: #1e2736;
            --hover-bg: #2a3648;
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
            line-height: 1.6;
        }

        /* Navigation */
        .navbar {
            background: rgba(15, 20, 25, 0.95);
            backdrop-filter: blur(10px);
            padding: 15px 30px;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info span {
            color: var(--gray);
        }

        .logout-btn {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 8px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: #ef4444;
            color: white;
        }

        .admin-btn {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
            padding: 8px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }

        .admin-btn:hover {
            background: #3b82f6;
            color: white;
        }

        /* Main Content */
        .main-content {
            margin-top: 80px;
            padding: 30px;
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

        .stats {
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

        /* Video Grid */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .section-header h2 {
            font-size: 24px;
            color: var(--light);
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 50px;
        }

        .video-card {
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .video-card:hover {
            transform: translateY(-10px);
            border-color: var(--primary);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .video-thumbnail {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .video-info {
            padding: 20px;
        }

        .video-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .video-meta {
            display: flex;
            justify-content: space-between;
            color: var(--gray);
            font-size: 14px;
            margin-bottom: 15px;
        }

        .play-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 0 0 12px 12px;
            cursor: pointer;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            transition: opacity 0.3s;
        }

        .play-btn:hover {
            opacity: 0.9;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--gray);
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
                flex-direction: column;
                gap: 15px;
            }

            .main-content {
                margin-top: 140px;
                padding: 15px;
            }

            .video-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }

            .welcome-section {
                padding: 30px 20px;
            }

            .welcome-section h1 {
                font-size: 28px;
            }
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="logo">🔐 SecureStream</div>
        <div class="user-info">
            <span>Welcome, <strong>
                    <?php echo htmlspecialchars($username); ?>
                </strong></span>
            <?php if ($isAdmin): ?>
                <a href="../admin/" class="admin-btn">Admin Panel</a>
            <?php endif; ?>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1>Secure Video Library</h1>
            <p>All videos are encrypted with military-grade AES-256 encryption. No downloads, only secure streaming.</p>
        </div>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <h3>Total Videos</h3>
                <div class="stat-number">
                    <?php echo count($videos); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Total Users</h3>
                <div class="stat-number">
                    <?php echo $userCount; ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>Security Level</h3>
                <div class="stat-number">AES-256</div>
            </div>
        </div>

        <!-- Video Library -->
        <div class="section-header">
            <h2>Encrypted Video Library</h2>
            <?php if ($isAdmin): ?>
                <a href="../admin/upload.php" class="admin-btn" style="text-decoration: none;">+ Upload Video</a>
            <?php endif; ?>
        </div>

        <?php if (empty($videos)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📁</div>
                <h3>No Videos Available</h3>
                <p>No encrypted videos have been uploaded yet.</p>
                <?php if ($isAdmin): ?>
                    <a href="../admin/upload.php" class="play-btn"
                        style="margin-top: 20px; border-radius: 8px; width: auto; display: inline-block; padding: 12px 30px;">
                        Upload Your First Video
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="video-grid" id="video-grid">
                <?php foreach ($videos as $video): ?>
                    <div class="video-card">
                        <img src="<?php echo $video['thumbnail']; ?>" alt="<?php echo htmlspecialchars($video['title']); ?>"
                            class="video-thumbnail"
                            onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIwIiBoZWlnaHQ9IjE4MCIgdmlld0JveD0iMCAwIDMyMCAxODAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PHJlY3Qgd2lkdGg9IjMyMCIgaGVpZ2h0PSIxODAiIGZpbGw9IiMxRTI3MzYiLz48dGV4dCB4PSI1MCUiIHk9IjUwJSIgZG9taW5hbnQtYmFzZWxpbmU9Im1pZGRsZSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZmlsbD0iIzhBOTRBNiIgZm9udC1mYW1pbHk9IkFyaWFsIiBmb250LXNpemU9IjE0Ij5WaWRlbyBUaHVtYm5haWw8L3RleHQ+PC9zdmc+'">
                        <div class="video-info">
                            <h3 class="video-title">
                                <?php echo htmlspecialchars($video['title']); ?>
                            </h3>
                            <div class="video-meta">
                                <span>
                                    <?php echo $video['chunks']; ?> chunks
                                </span>
                                <span>
                                    <?php echo date('M d, Y', strtotime($video['created_at'])); ?>
                                </span>
                            </div>
                        </div>
                        <a href="player.php?id=<?php echo $video['id']; ?>" class="play-btn">▶ Play Securely</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '?logout';
            }
        }

        // Add some interactivity
        document.addEventListener('DOMContentLoaded', function () {
            // Add click animation to video cards
            const videoCards = document.querySelectorAll('.video-card');
            videoCards.forEach(card => {
                card.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('play-btn')) {
                        this.style.transform = 'scale(0.98)';
                        setTimeout(() => {
                            this.style.transform = '';
                        }, 200);
                    }
                });
            });

            // Auto-refresh video list every 30 seconds
            setInterval(() => {
                fetch('../api/get_video_list.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // You could update the video grid here
                            console.log('Video list updated');
                        }
                    })
                    .catch(error => console.error('Failed to update video list:', error));
            }, 30000);
        });
    </script>
</body>

</html>