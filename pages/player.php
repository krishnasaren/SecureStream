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

if (!isset($_GET['uuid'])) {
    http_response_code(403);
    exit('Direct access forbidden');
}



$payload = validateTimeBoundToken($_GET['uuid'] ?? '');
if (!$payload) {
    http_response_code(403);
    exit('Invalid or expired uuid token');
}
$vid = $payload['vid'] ?? null;


// Get video ID
$videoId = $_POST['id'] ?? '';
if (empty($videoId)) {
    die('Error: Video ID required');
}

if ($videoId !== $vid) {
    die('Error: Video ID does not match token');
}

if ($payload['uid'] !== $_SESSION['user_id']) {
    http_response_code(403);
    exit('Token-user mismatch');
}


// Get video info
$videoInfo = getVideoInfo($videoId);
if (!$videoInfo) {
    die('Error: Video not found or not encrypted');
}

$title = $videoInfo['title'];
$chunkCount = $videoInfo['chunk_count'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo htmlspecialchars($title); ?> | SecureStream Player
    </title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5568d3;
            --secondary: #764ba2;
            --dark: #000000;
            --light: #ffffff;
            --gray: #8a94a6;
            --gray-light: #b4bcc8;
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --overlay-bg: rgba(0, 0, 0, 0.75);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--dark);
            color: var(--light);
            overflow: hidden;
            height: calc(var(--vh, 1vh) * 100);
            /*100vh*/
        }

        /* Player Container */
        .player-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: calc(var(--vh, 1vh) * 100);
            /*100% height to cover full viewport*/
            background: #000;
            z-index: 10000;
        }

        #secure-video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            outline: none;
            background: #000;
        }

        /* Overlay Controls */
        .player-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            opacity: 0;
            transition: opacity 0.3s ease;
            background: linear-gradient(to bottom,
                    rgba(0, 0, 0, 0.8) 0%,
                    transparent 20%,
                    transparent 80%,
                    rgba(0, 0, 0, 0.9) 100%);
            pointer-events: none;
        }

        /*.player-container:hover .player-overlay,*/
        .player-overlay.force-show {
            opacity: 1;
            pointer-events: all;
        }

        /* Top Bar */
        .top-bar {
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(to bottom, rgba(0, 0, 0, 0.8), transparent);
            z-index: 2;
        }

        .back-btn {
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary);
            transform: translateX(-2px);
        }

        .security-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .security-badge {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(16, 185, 129, 0.3);
            backdrop-filter: blur(10px);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        .video-title {
            font-size: 18px;
            font-weight: 600;
            color: white;
            max-width: 400px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Center Play Button */
        .center-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80px;
            height: 80px;
            background: rgba(102, 126, 234, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 32px;
            color: white;
            opacity: 0;
            transition: all 0.3s;
            pointer-events: none;
            z-index: 3;
        }

        .center-play-btn.show {
            opacity: 1;
            pointer-events: all;
        }

        .center-play-btn:hover {
            transform: translate(-50%, -50%) scale(1.1);
            background: var(--primary-dark);
        }

        /* Bottom Controls */
        .bottom-controls {
            padding: 20px 30px;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.9), transparent);
            z-index: 2;
        }

        .progress-container {
            margin-bottom: 20px;
            cursor: pointer;
            position: relative;
            padding: 8px 0;
        }

        .progress-bar {
            width: 100%;
            height: 5px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            overflow: visible;
            position: relative;
        }

        .progress {
            height: 100%;
            background: var(--primary);
            width: 0%;
            transition: width 0.1s linear;
            border-radius: 3px;
            position: relative;
        }

        .progress::after {
            content: '';
            position: absolute;
            right: -6px;
            top: 50%;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            background: var(--primary);
            border-radius: 50%;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .progress-container:hover .progress::after {
            opacity: 1;
        }

        .buffered-bar {
            position: absolute;
            height: 100%;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
            left: 0;
            top: 0;
        }

        .time-display {
            display: flex;
            justify-content: space-between;
            color: var(--gray);
            font-size: 14px;
            margin-top: 8px;
        }

        .control-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .left-controls,
        .right-controls {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .control-btn {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s;
            padding: 8px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
        }

        .control-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--primary);
            transform: scale(1.1);
        }

        .control-btn:active {
            transform: scale(0.95);
        }

        .volume-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .volume-slider {
            width: 80px;
            height: 5px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            cursor: pointer;
            position: relative;
        }

        .volume-level {
            position: absolute;
            height: 100%;
            background: var(--primary);
            border-radius: 3px;
            width: 70%;
        }

        /* Loading Screen */
        .loading-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--dark);
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 10001;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .loading-screen.show {
            display: flex;
            opacity: 1;
        }

        .loader {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        .decryption-status {
            text-align: center;
            color: var(--light);
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .decryption-step {
            color: var(--gray-light);
            font-size: 14px;
            margin-top: 5px;
        }

        .decryption-progress {
            width: 250px;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            margin: 20px 0;
            overflow: hidden;
        }

        .decryption-progress-bar {
            height: 100%;
            background: var(--success);
            width: 0%;
            transition: width 0.3s;
        }

        /* Security Warning */
        .security-warning {
            position: absolute;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 12px;
            text-align: center;
            max-width: 80%;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(239, 68, 68, 0.3);
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .security-warning.show {
            opacity: 1;
        }

        /* Buffer Indicator */
        .buffer-indicator {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.8);
            padding: 20px 30px;
            border-radius: 12px;
            display: none;
            align-items: center;
            gap: 15px;
            backdrop-filter: blur(10px);
            z-index: 10002;
        }

        .buffer-indicator.show {
            display: flex;
        }

        .buffer-spinner {
            width: 30px;
            height: 30px;
            border: 3px solid rgba(255, 255, 255, 0.2);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        /* Keyboard Shortcuts Hint */
        .shortcuts-hint {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.9);
            padding: 30px;
            border-radius: 12px;
            color: white;
            max-width: 500px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
            z-index: 10003;
        }

        .shortcuts-hint.show {
            opacity: 1;
            pointer-events: all;
        }

        .shortcuts-hint h3 {
            margin-bottom: 20px;
            color: var(--primary);
        }

        .shortcut-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .shortcut-key {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Hide browser video controls */
        #secure-video::-webkit-media-controls {
            display: none !important;
        }

        #secure-video::-webkit-media-controls-panel {
            display: none !important;
        }

        #secure-video::-webkit-media-controls-play-button {
            display: none !important;
        }

        #secure-video::-webkit-media-controls-start-playback-button {
            display: none !important;
        }

        /* Responsive */
        @media (max-width: 768px) {

            .top-bar,
            .bottom-controls {
                padding: 15px 20px;
            }

            .video-title {
                max-width: 200px;
                font-size: 16px;
            }

            .control-btn {
                font-size: 20px;
                width: 36px;
                height: 36px;
            }

            .center-play-btn {
                width: 60px;
                height: 60px;
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="player-container">
        <!-- Loading Screen -->
        <div class="loading-screen" id="loading-screen">
            <div class="loader"></div>
            <div class="decryption-status" id="decryption-status">Initializing Secure Player...</div>
            <div class="decryption-step" id="decryption-step">Loading encryption keys...</div>
            <div class="decryption-progress">
                <div class="decryption-progress-bar" id="decryption-progress"></div>
            </div>
        </div>

        <!-- Buffer Indicator -->
        <div class="buffer-indicator" id="buffer-indicator">
            <div class="buffer-spinner"></div>
            <span>Buffering...</span>
        </div>


        <!--Quality Swithing-->
        <div class="quality-switching" id="quality-switching">
            <div class="buffer-spinner"></div>
            <span>Switching quality...</span>
        </div>

        <div class="audio-switching" id="audio-switching">
            <div class="audio-switching-icon"></div>
            <div class="audio-switching-text">Switching audio track...</div>
        </div>

        <!-- Audio Changed Notification -->
        <div class="audio-changed-notification" id="audio-changed-notification">
            Audio track changed
        </div>

        <!-- Video Element -->
        <video id="secure-video" playsinline></video><!--muted autoplay-->

        <!-- Center Play Button -->
        <div class="center-play-btn" id="center-play-btn" onclick="togglePlay()">
            <svg class="center-icon" viewBox="0 0 100 100" aria-hidden="true">
                <!-- subtle outer ring -->
                <circle
                    cx="50"
                    cy="50"
                    r="46"
                    fill="none"
                    stroke="rgba(255,255,255,0.25)"
                    stroke-width="4" />

                <!-- play triangle -->
                <polygon
                    points="42,30 72,50 42,70"
                    fill="#ffffff" />
            </svg>
        </div>

        <!-- Overlay Controls -->
        <div class="player-overlay" id="player-overlay">
            <!-- Top Bar -->
            <div class="top-bar">
                <button class="back-btn" onclick="exitPlayer()">
                    ← Back to Library
                </button>
                <div class="security-info">
                    <div class="video-title">
                        <?php echo htmlspecialchars($title); ?>
                    </div>
                    <div class="security-badge">
                        <span>🔒</span>
                        <span>AES-256 Active</span>
                    </div>
                </div>
            </div>

            <!-- Bottom Controls -->
            <div class="bottom-controls">
                <div class="progress-container" onclick="seekVideo(event)">
                    <div class="progress-bar">
                        <div class="buffered-bar" id="buffered-bar"></div>
                        <div class="progress" id="progress-bar"></div>
                    </div>
                    <div class="time-display">
                        <span id="current-time">00:00</span>
                        <span id="duration">00:00</span>
                    </div>
                </div>

                <div class="control-buttons">
                    <div class="left-controls">
                        <button class="control-btn" id="play-pause-btn" onclick="togglePlay()"
                            title="Play/Pause (Space)">▶</button><!--❚❚-->
                        <div class="volume-container">
                            <button class="control-btn" id="mute-btn" onclick="toggleMute()"
                                title="Mute (M)">🔊</button>
                            <div class="volume-slider" onclick="setVolume(event)">
                                <div class="volume-level" id="volume-level"></div>
                            </div>
                        </div>
                        <span id="speed-display" style="color: var(--gray); font-size: 14px;">1.0x</span>
                    </div>

                    <div class="right-controls">
                        <button class="control-btn" onclick="skipBackward()" title="Backward 10s (←)"><svg viewBox="0 0 24 24" class="icon">
                                <polygon points="11,5 3,12 11,19"></polygon>
                                <polygon points="21,5 13,12 21,19"></polygon>
                            </svg></button>
                        <button class="control-btn" onclick="skipForward()" title="Forward 10s (→)"><svg viewBox="0 0 24 24" class="icon">
                                <polygon points="3,5 11,12 3,19"></polygon>
                                <polygon points="13,5 21,12 13,19"></polygon>
                            </svg></button>
                        <button class="control-btn" onclick="changeSpeed(-0.25)" title="Slower">-</button>
                        <button class="control-btn" onclick="changeSpeed(0.25)" title="Faster">+</button>
                        <button class="control-btn" id="pip-btn" onclick="togglePiP()"
                            title="Picture in Picture"><svg viewBox="0 0 24 24" class="icon">
                                <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                <rect x="12" y="10" width="7" height="6" rx="1" fill="#000"></rect>
                            </svg></button>
                        <button class="control-btn" id="fullscreen-btn" onclick="toggleFullscreen()"
                            title="Fullscreen (F)">⛶</button>
                        <!-- Quality Selector -->
                        <div class="quality-selector-container">
                            <button class="control-btn" id="quality-btn" onclick="toggleQualityMenu()" title="Quality">
                                <span id="current-quality-text">720p</span>
                            </button>
                            <div class="quality-menu" id="quality-menu">
                                <!-- Will be populated dynamically -->
                            </div>
                        </div>
                        <!-- Audio Track Selector -->
                        <div class="audio-selector-container" id="audio-selector-container" style="display: none;">
                            <button class="control-btn" id="audio-btn" onclick="toggleAudioMenu()" title="Audio Track">
                                🎵
                            </button>
                            <div class="audio-menu" id="audio-menu">
                                <!-- Will be populated dynamically -->
                            </div>
                        </div>

                        <!-- Subtitle Selector -->
                        <div class="subtitle-selector-container" id="subtitle-selector-container" style="display: none;">
                            <button class="control-btn" id="subtitle-btn" onclick="toggleSubtitleMenu()" title="Subtitles">
                                CC
                            </button>
                            <div class="subtitle-menu" id="subtitle-menu">
                                <div class="menu-item" onclick="disableSubtitles()">Off</div>
                                <!-- Will be populated dynamically -->
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <!-- Security Warning -->
        <div class="security-warning" id="security-warning">
            <span>⚠️</span>
            <span>Protected Content: Screen recording and downloading disabled</span>
        </div>

        <!-- Keyboard Shortcuts -->
        <div class="shortcuts-hint" id="shortcuts-hint">
            <h3>Keyboard Shortcuts</h3>
            <div class="shortcut-item">
                <span>Play/Pause</span>
                <span class="shortcut-key">Space / K</span>
            </div>
            <div class="shortcut-item">
                <span>Seek Forward/Backward</span>
                <span class="shortcut-key">→ / ←</span>
            </div>
            <div class="shortcut-item">
                <span>Volume Up/Down</span>
                <span class="shortcut-key">↑ / ↓</span>
            </div>
            <div class="shortcut-item">
                <span>Mute</span>
                <span class="shortcut-key">M</span>
            </div>
            <div class="shortcut-item">
                <span>Fullscreen</span>
                <span class="shortcut-key">F</span>
            </div>
            <div class="shortcut-item">
                <span>Speed Up/Down</span>
                <span class="shortcut-key">+ / -</span>
            </div>
            <div class="shortcut-item">
                <span>Close This</span>
                <span class="shortcut-key">?</span>
            </div>
        </div>
    </div>

    <!-- Include Enhanced Crypto.js -->
    <script src="../assets/js/crypto.js"></script>

    <script>
        // ============================================
        // SECURITY MEASURES
        // ============================================
        document.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('keydown', e => {
            const disabledKeys = ['F12', 'PrintScreen'];
            const disabledCombos = [
                e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key),
                e.ctrlKey && ['U', 'S', 'P'].includes(e.key.toUpperCase()),
                e.metaKey && e.key.toLowerCase() === 'r'
            ];

            if (disabledKeys.includes(e.key) || disabledCombos.some(combo => combo)) {
                e.preventDefault();
                return false;
            }
            if (e.key.toLowerCase() === 'a' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                cycleAudioTrack();
            }
        });
        const csrf_token = <?php echo json_encode($_SESSION['csrf_token']); ?>;

        // ============================================
        // GLOBAL VARIABLES
        // ============================================
        let videoPlayer;
        let videoDecryptor;
        let playerState = {
            isPlaying: false,
            isMuted: false,
            playbackRate: 1.0,
            volume: 0.7,
            currentTime: 0,
            duration: 0,
            buffered: 0,
            isInitialized: false,
            isSeeking: false,
            hasEnded: false
        };

        let isExitingPlayer = false;

        const videoId = '<?php echo $videoId; ?>';
        const totalChunks = <?php echo $chunkCount; ?>;

        let uiTimeout = null;
        let progressUpdateInterval = null;

        // ============================================
        // INITIALIZATION
        // ============================================
        async function initPlayer() {
            try {
                videoPlayer = document.getElementById('secure-video');

                showLoading('Fetching encryption keys...', 10);

                // Initialize decryptor
                videoDecryptor = new VideoDecryptor();
                window.videoDecryptor = videoDecryptor;

                showLoading('Requesting decryption key...', 30);
                const keyLoaded = await videoDecryptor.init(videoId);

                if (!keyLoaded) {
                    throw new Error('Failed to load encryption key');
                }

                showLoading('Building secure stream...', 50);
                await videoDecryptor.prepareMediaSource(videoPlayer);

                showLoading('Loading tracks and qualities...', 70);

                // Populate UI controls
                populateQualityMenu(
                    videoDecryptor.availableQualities,
                    videoDecryptor.currentQuality
                );

                if (videoDecryptor.audioTracks.length > 1) {
                    populateAudioMenu(videoDecryptor.audioTracks);
                }

                if (videoDecryptor.subtitleTracks.length > 0) {
                    populateSubtitleMenu(videoDecryptor.subtitleTracks);
                }
                updateKeyboardShortcutsHint();

                // Update quality display
                document.getElementById('current-quality-text').textContent =
                    videoDecryptor.currentQuality;

                showLoading('Finalizing...', 90);

                // Wait for metadata
                await new Promise((resolve) => {
                    videoPlayer.addEventListener('loadedmetadata', resolve, {
                        once: true
                    });
                });

                showLoading('Ready!', 100);

                // Small delay for smooth transition
                await new Promise(resolve => setTimeout(resolve, 500));

                // Setup and show player
                setupEventListeners();
                setupKeyboardShortcuts();
                restorePlayerSettings();
                updateDurationDisplay();

                hideLoading();
                playerState.isInitialized = true;

                // Show security warning briefly
                showSecurityWarning();

                console.log('✅ Enhanced player initialized successfully');
                console.log(`📊 Quality: ${videoDecryptor.currentQuality}, ` +
                    `Audio: ${videoDecryptor.audioTracks.length}, ` +
                    `Subtitles: ${videoDecryptor.subtitleTracks.length}`);

            } catch (error) {
                console.error('❌ Player initialization failed:', error);
                showError('Failed to initialize secure player: ' + error.message);
            }
        }

        // ============================================
        // EVENT LISTENERS
        // ============================================
        function setupEventListeners() {
            // Playback events
            videoPlayer.addEventListener('play', () => {
                if (playerState.hasEnded) return;
                playerState.isPlaying = true;
                updatePlayButton(true);
                hideCenterPlayButton();
            });

            videoPlayer.addEventListener('pause', () => {
                if (playerState.hasEnded) return;
                playerState.isPlaying = false;
                updatePlayButton(false);
                showCenterPlayButton();
            });

            videoPlayer.addEventListener('ended', () => {
                playerState.isPlaying = false;
                playerState.hasEnded = true;
                updatePlayButton(false);
                showCenterPlayButton();
                hideBuffering();
                console.log('📺 Playback ended');
            });

            // Seeking events
            videoPlayer.addEventListener('seeking', () => {
                playerState.isSeeking = true;
                if (!isBuffered(videoPlayer.currentTime)) {
                    showBuffering();
                }
            });

            videoPlayer.addEventListener('seeked', () => {
                playerState.isSeeking = false;
                if (isBuffered(videoPlayer.currentTime)) {
                    hideBuffering();
                }
            });

            // Buffering events
            videoPlayer.addEventListener('waiting', () => {
                // Don't show buffering at end of stream
                if (videoDecryptor?.streamEnded &&
                    videoPlayer.currentTime >= videoPlayer.duration - 0.5) {
                    return;
                }
                showBuffering();
            });

            videoPlayer.addEventListener('playing', () => {
                hideBuffering();
            });

            videoPlayer.addEventListener('canplay', () => {
                hideBuffering();
            });

            // Time update
            videoPlayer.addEventListener('timeupdate', () => {
                updateProgress();
                updateBufferedDisplay();

                // Handle end-of-stream for MediaSource
                if (videoDecryptor?.streamEnded &&
                    videoPlayer.duration &&
                    videoPlayer.currentTime >= videoPlayer.duration - 0.3 &&
                    !playerState.hasEnded) {

                    playerState.hasEnded = true;
                    playerState.isPlaying = false;
                    updatePlayButton(false);
                    showCenterPlayButton();
                    hideBuffering();
                }
            });

            // Volume change
            videoPlayer.addEventListener('volumechange', updateVolumeDisplay);

            // Error handling
            videoPlayer.addEventListener('error', (e) => {
                if (isExitingPlayer) {
                    return;
                }
                console.error('❌ Video error:', e);
                showError('Video playback error. Attempting recovery...');
                attemptRecovery();
            });

            // UI auto-hide
            const playerContainer = document.querySelector('.player-container');
            playerContainer.addEventListener('mousemove', resetUITimeout);
            playerContainer.addEventListener('touchstart', resetUITimeout);

            // Click to play/pause
            videoPlayer.addEventListener('click', () => {
                togglePlay();
            });

            // Initial volume
            updateVolumeDisplay();
            startProgressUpdates();
        }

        // ============================================
        // KEYBOARD SHORTCUTS
        // ============================================
        function setupKeyboardShortcuts() {
            document.addEventListener('keydown', (e) => {
                // Ignore if typing in input
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                    return;
                }

                switch (e.key.toLowerCase()) {
                    case ' ':
                    case 'k':
                        e.preventDefault();
                        togglePlay();
                        break;
                    case 'arrowleft':
                        e.preventDefault();
                        skipBackward();
                        break;
                    case 'arrowright':
                        e.preventDefault();
                        skipForward();
                        break;
                    case 'arrowup':
                        e.preventDefault();
                        adjustVolume(0.1);
                        break;
                    case 'arrowdown':
                        e.preventDefault();
                        adjustVolume(-0.1);
                        break;
                    case 'm':
                        e.preventDefault();
                        toggleMute();
                        break;
                    case 'f':
                        e.preventDefault();
                        toggleFullscreen();
                        break;
                    case '+':
                    case '=':
                        e.preventDefault();
                        changeSpeed(0.25);
                        break;
                    case '-':
                    case '_':
                        e.preventDefault();
                        changeSpeed(-0.25);
                        break;
                    case '?':
                        e.preventDefault();
                        toggleShortcutsHint();
                        break;
                    case '0':
                    case '1':
                    case '2':
                    case '3':
                    case '4':
                    case '5':
                    case '6':
                    case '7':
                    case '8':
                    case '9':
                        e.preventDefault();
                        const percent = parseInt(e.key) * 10;
                        seekToPercent(percent);
                        break;
                }
            });
        }

        // ============================================
        // PLAYBACK CONTROLS
        // ============================================
        async function togglePlay() {
            try {
                if (playerState.hasEnded) {
                    await restartVideo();
                    return;
                }

                if (videoPlayer.paused) {
                    await videoPlayer.play();
                } else {
                    videoPlayer.pause();
                }
            } catch (error) {
                console.error('Play/pause error:', error);
            }
        }

        async function restartVideo() {
            console.log('🔄 Restarting video from beginning');
            showBuffering();

            playerState.hasEnded = false;
            playerState.isPlaying = false;

            await videoDecryptor.restart(videoPlayer);
            videoPlayer.currentTime = 0;

            await videoPlayer.play();
            hideBuffering();
        }

        function skipBackward() {
            seekToTime(Math.max(0, videoPlayer.currentTime - 10));
        }

        function skipForward() {
            const newTime = Math.min(videoPlayer.duration, videoPlayer.currentTime + 10);
            seekToTime(newTime);
        }

        async function seekToTime(time) {
            if (!videoPlayer.duration) return;

            const wasPlaying = !videoPlayer.paused;
            showBuffering();

            // Safety margin from end
            const safeTime = Math.min(time, videoPlayer.duration - 1.0);

            if (videoDecryptor.streamEnded) {
                await videoDecryptor.restart(videoPlayer);
            }

            await videoDecryptor.seek(safeTime);

            if (wasPlaying) {
                await videoPlayer.play();
            }

            hideBuffering();
        }

        function seekToPercent(percent) {
            const time = (videoPlayer.duration * percent) / 100;
            seekToTime(time);
        }

        async function seekVideo(event) {
            const container = event.currentTarget;
            const rect = container.getBoundingClientRect();
            const clientX = event.touches ?
                event.touches[0].clientX :
                event.clientX;

            const percent = (clientX - rect.left) / rect.width;
            const time = videoPlayer.duration * percent;

            await seekToTime(time);
        }

        function toggleMute() {
            videoPlayer.muted = !videoPlayer.muted;
            playerState.isMuted = videoPlayer.muted;
            updateVolumeDisplay();
            savePlayerSettings();
        }

        function adjustVolume(delta) {
            const newVolume = Math.max(0, Math.min(1, videoPlayer.volume + delta));
            videoPlayer.volume = newVolume;
            videoPlayer.muted = false;
            playerState.volume = newVolume;
            updateVolumeDisplay();
            savePlayerSettings();
        }

        function setVolume(event) {
            const slider = event.currentTarget;
            const rect = slider.getBoundingClientRect();
            const percent = (event.clientX - rect.left) / rect.width;
            const volume = Math.max(0, Math.min(1, percent));

            videoPlayer.volume = volume;
            videoPlayer.muted = false;
            playerState.volume = volume;
            updateVolumeDisplay();
            savePlayerSettings();
        }

        function changeSpeed(delta) {
            playerState.playbackRate = Math.max(0.25, Math.min(4.0, playerState.playbackRate + delta));
            videoPlayer.playbackRate = playerState.playbackRate;
            document.getElementById('speed-display').textContent = playerState.playbackRate.toFixed(2) + 'x';
            savePlayerSettings();
        }

        async function togglePiP() {
            try {
                if (document.pictureInPictureElement) {
                    await document.exitPictureInPicture();
                } else {
                    await videoPlayer.requestPictureInPicture();
                }
            } catch (error) {
                console.log('PiP not supported or failed:', error);
            }
        }
        async function lockOrientationLandscape() {
            try {
                if (screen.orientation && screen.orientation.lock) {
                    await screen.orientation.lock('landscape');
                }
            } catch (e) {}
        }

        async function unlockOrientation() {
            try {
                if (screen.orientation && screen.orientation.unlock) {
                    screen.orientation.unlock();
                }
            } catch (e) {}
        }


        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().then(lockOrientationLandscape);
            } else {
                document.exitFullscreen().then(unlockOrientation);
            }
        }

        function setMobileViewportHeight() {
            const vh = window.innerHeight * 0.01;
            document.documentElement.style.setProperty('--vh', `${vh}px`);
        }

        setMobileViewportHeight();
        window.addEventListener('resize', setMobileViewportHeight);
        window.addEventListener('orientationchange', () => {
            setTimeout(setMobileViewportHeight, 300);
        });



        // ============================================
        // UI UPDATES
        // ============================================
        function updatePlayButton(isPlaying) {
            const btn = document.getElementById('play-pause-btn');
            btn.textContent = isPlaying ? '❚❚' : '▶';
        }

        function showCenterPlayButton() {
            document.getElementById('center-play-btn').classList.add('show');
        }

        function hideCenterPlayButton() {
            document.getElementById('center-play-btn').classList.remove('show');
        }

        function updateVolumeDisplay() {
            const volume = videoPlayer.volume;
            const volumeLevel = document.getElementById('volume-level');
            const muteBtn = document.getElementById('mute-btn');

            if (volumeLevel) {
                volumeLevel.style.width = (volume * 100) + '%';
            }

            if (videoPlayer.muted || volume === 0) {
                muteBtn.textContent = '🔇';
            } else if (volume < 0.5) {
                muteBtn.textContent = '🔉';
            } else {
                muteBtn.textContent = '🔊';
            }
        }

        function updateProgress() {
            if (!videoPlayer.duration) return;

            const currentTime = videoPlayer.currentTime;
            const duration = videoPlayer.duration;
            const percent = (currentTime / duration) * 100;

            document.getElementById('progress-bar').style.width = percent + '%';
            document.getElementById('current-time').textContent = formatTime(currentTime);

            playerState.currentTime = currentTime;
        }

        function updateBufferedDisplay() {
            if (!videoPlayer.buffered.length) return;

            const duration = videoPlayer.duration;
            const buffered = videoPlayer.buffered.end(videoPlayer.buffered.length - 1);
            const percent = (buffered / duration) * 100;

            document.getElementById('buffered-bar').style.width = percent + '%';
            playerState.buffered = buffered;
        }

        function updateDurationDisplay() {
            const duration = videoPlayer.duration;
            if (duration > 0) {
                document.getElementById('duration').textContent = formatTime(duration);
                playerState.duration = duration;
            }
        }

        function startProgressUpdates() {
            if (progressUpdateInterval) {
                clearInterval(progressUpdateInterval);
            }

            progressUpdateInterval = setInterval(() => {
                if (!videoPlayer.paused) {
                    updateProgress();
                }
            }, 100);
        }

        function cycleAudioTrack() {
            if (!window.videoDecryptor || window.videoDecryptor.audioTracks.length <= 1) {
                console.log('Only one audio track available');
                return;
            }

            const currentTrack = window.videoDecryptor.currentAudioTrack;
            const nextTrack = (currentTrack + 1) % window.videoDecryptor.audioTracks.length;

            console.log(`🔄 Cycling audio: ${currentTrack} → ${nextTrack}`);

            switchAudioTrack(nextTrack);
        }

        function resetUITimeout() {
            const overlay = document.getElementById('player-overlay');
            overlay.classList.add('force-show');

            if (uiTimeout) {
                clearTimeout(uiTimeout);
            }

            if (!videoPlayer.paused) {
                uiTimeout = setTimeout(() => {
                    overlay.classList.remove('force-show');
                }, 3000);
            }
        }

        // ============================================
        // LOADING & BUFFERING UI
        // ============================================
        function showLoading(message, progress = null) {
            const screen = document.getElementById('loading-screen');
            const status = document.getElementById('decryption-status');
            const step = document.getElementById('decryption-step');
            const progressBar = document.getElementById('decryption-progress');

            status.textContent = 'Initializing Secure Player';
            step.textContent = message;

            if (progress !== null) {
                progressBar.style.width = progress + '%';
            }

            screen.classList.add('show');
        }

        function displayAudioTrackInfo() {
            if (!window.videoDecryptor || window.videoDecryptor.audioTracks.length === 0) {
                return;
            }

            const currentTrack = window.videoDecryptor.audioTracks[window.videoDecryptor.currentAudioTrack];

            if (currentTrack) {
                console.log('🎵 Current audio track:', currentTrack.title || 'Unknown');
                console.log('   Language:', currentTrack.language || 'Unknown');
                console.log('   Codec:', currentTrack.codec || 'Unknown');
            }
        }

        function hideLoading() {
            const screen = document.getElementById('loading-screen');
            screen.classList.remove('show');
        }

        function showBuffering() {
            const indicator = document.getElementById('buffer-indicator');
            if (indicator) {
                indicator.classList.add('show');
            }
        }

        function hideBuffering() {
            const indicator = document.getElementById('buffer-indicator');
            if (indicator) {
                indicator.classList.remove('show');
            }
        }

        function showSecurityWarning() {
            const warning = document.getElementById('security-warning');
            warning.classList.add('show');

            setTimeout(() => {
                warning.classList.remove('show');
            }, 5000);
        }

        function toggleShortcutsHint() {
            const hint = document.getElementById('shortcuts-hint');
            hint.classList.toggle('show');
        }

        function updateKeyboardShortcutsHint() {
            const shortcutsHint = document.getElementById('shortcuts-hint');

            if (window.videoDecryptor && window.videoDecryptor.audioTracks.length > 1) {
                // Add audio track shortcut to the hints
                const audioShortcut = document.createElement('div');
                audioShortcut.className = 'shortcut-item';
                audioShortcut.innerHTML = `
            <span>Cycle Audio Track</span>
            <span class="shortcut-key">A</span>
        `;

                // Insert before "Close This" shortcut
                const closeShortcut = shortcutsHint.querySelector('.shortcut-item:last-child');
                shortcutsHint.insertBefore(audioShortcut, closeShortcut);
            }
        }

        // ============================================
        // UTILITY FUNCTIONS
        // ============================================
        function isBuffered(time) {
            for (let i = 0; i < videoPlayer.buffered.length; i++) {
                if (time >= videoPlayer.buffered.start(i) && time <= videoPlayer.buffered.end(i)) {
                    return true;
                }
            }
            return false;
        }

        function formatTime(seconds) {
            if (isNaN(seconds)) return '00:00';

            const hours = Math.floor(seconds / 3600);
            const mins = Math.floor((seconds % 3600) / 60);
            const secs = Math.floor(seconds % 60);

            if (hours > 0) {
                return `${hours}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            }
            return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        function savePlayerSettings() {
            try {
                localStorage.setItem('playerSettings', JSON.stringify({
                    volume: playerState.volume,
                    playbackRate: playerState.playbackRate,
                    isMuted: playerState.isMuted
                }));
            } catch (error) {
                console.log('Unable to save settings:', error);
            }
        }

        function restorePlayerSettings() {
            try {
                const saved = localStorage.getItem('playerSettings');
                if (saved) {
                    const settings = JSON.parse(saved);
                    videoPlayer.volume = settings.volume || 0.7;
                    videoPlayer.muted = settings.isMuted || false;
                    videoPlayer.playbackRate = settings.playbackRate || 1.0;

                    playerState.volume = videoPlayer.volume;
                    playerState.isMuted = videoPlayer.muted;
                    playerState.playbackRate = videoPlayer.playbackRate;

                    document.getElementById('speed-display').textContent = playerState.playbackRate.toFixed(2) + 'x';
                }
            } catch (error) {
                console.log('Unable to restore settings:', error);
            }
        }

        async function attemptRecovery() {
            console.log('🔧 Attempting recovery...');
            if (isExitingPlayer) {
                return;
            }

            try {
                const currentTime = videoPlayer.currentTime;
                await videoDecryptor.restart(videoPlayer);

                if (currentTime > 0) {
                    await seekToTime(currentTime);
                }

                if (playerState.isPlaying) {
                    await videoPlayer.play();
                }

                console.log('✅ Recovery successful');
            } catch (error) {
                console.error('❌ Recovery failed:', error);
                showError('Unable to recover playback. Please refresh the page.');
            }
        }

        function showError(message) {
            alert(message);
        }

        function exitPlayer() {
            if (confirm('Exit secure player? Your viewing position will not be saved.')) {
                unlockOrientation();
                isExitingPlayer = true;
                // Cleanup
                if (progressUpdateInterval) {
                    clearInterval(progressUpdateInterval);
                }

                if (videoDecryptor) {
                    videoDecryptor.cleanup();
                }

                if (videoPlayer) {
                    videoPlayer.pause();
                    videoPlayer.src = '';
                }

                window.location.href = 'dashboard.php';
            }
        }


        // Quality Menu Functions
        function toggleQualityMenu() {
            const menu = document.getElementById('quality-menu');
            menu.classList.toggle('show');

            // Close other menus
            document.getElementById('audio-menu').classList.remove('show');
            document.getElementById('subtitle-menu').classList.remove('show');
        }

        function toggleAudioMenu() {
            const menu = document.getElementById('audio-menu');
            menu.classList.toggle('show');

            document.getElementById('quality-menu').classList.remove('show');
            document.getElementById('subtitle-menu').classList.remove('show');
        }

        function toggleSubtitleMenu() {
            const menu = document.getElementById('subtitle-menu');
            menu.classList.toggle('show');

            document.getElementById('quality-menu').classList.remove('show');
            document.getElementById('audio-menu').classList.remove('show');
        }
        async function switchQuality(quality) {
            if (!window.videoDecryptor) return;

            document.getElementById('quality-menu').classList.remove('show');

            const switchingIndicator = document.getElementById('quality-switching');
            switchingIndicator.classList.add('show');

            try {
                const success = await window.videoDecryptor.switchQuality(quality);

                if (success) {
                    document.getElementById('current-quality-text').textContent = quality;
                    updateQualityMenuActive(quality);

                    // Show success notification
                    showNotification(`Quality changed to ${quality}`, 'success');
                } else {
                    showNotification('Failed to switch quality', 'error');
                }
            } catch (error) {
                console.error('Quality switch error:', error);
                showNotification('Quality switch error', 'error');
            } finally {
                switchingIndicator.classList.remove('show');
            }
        }
        async function switchAudioTrack(index) {
            if (!window.videoDecryptor) {
                console.error('Video decryptor not initialized');
                return;
            }

            if (index < 0 || index >= window.videoDecryptor.audioTracks.length) {
                console.error('Invalid audio track index:', index);
                return;
            }

            if (window.videoDecryptor.currentAudioTrack === index) {
                console.log('Already on this audio track');
                document.getElementById('audio-menu').classList.remove('show');
                return;
            }

            document.getElementById('audio-menu').classList.remove('show');

            const switchingIndicator = document.getElementById('audio-switching');
            if (switchingIndicator) {
                switchingIndicator.classList.add('show');
            }

            const audioBtn = document.getElementById('audio-btn');
            if (audioBtn) {
                audioBtn.disabled = true;
                audioBtn.style.opacity = '0.5';
            }

            try {
                const success = await window.videoDecryptor.switchAudioTrackWithRetry(index, 2);

                if (success) {
                    updateAudioMenuActive(index);

                    const trackName = window.videoDecryptor.audioTracks[index].title ||
                        `Audio Track ${index + 1}`;
                    const trackLang = window.videoDecryptor.audioTracks[index].language || 'unknown';

                    showAudioChangeNotification(trackName, trackLang, true);

                    console.log(`✅ Audio track switched successfully to: ${trackName}`);

                } else {
                    showNotification('Failed to switch audio track', 'error');
                    console.error('❌ Audio track switch failed');
                }

            } catch (error) {
                console.error('❌ Audio track switch error:', error);
                showNotification('Audio switch error', 'error');

            } finally {
                if (switchingIndicator) {
                    switchingIndicator.classList.remove('show');
                }

                if (audioBtn) {
                    audioBtn.disabled = false;
                    audioBtn.style.opacity = '1';
                }
            }
        }


        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
            position: fixed;
            top: 100px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            z-index: 10010;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            animation: slideDown 0.3s ease;
            `;

            if (type === 'success') {
                notification.style.background = 'rgba(16, 185, 129, 0.95)';
                notification.style.color = 'white';
            } else if (type === 'error') {
                notification.style.background = 'rgba(239, 68, 68, 0.95)';
                notification.style.color = 'white';
            } else {
                notification.style.background = 'rgba(102, 126, 234, 0.95)';
                notification.style.color = 'white';
            }

            notification.textContent = message;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.3s';
                setTimeout(() => notification.remove(), 300);
            }, 2000);
        }


        function showAudioChangeNotification(trackName, language, success) {
            const notification = document.getElementById('audio-changed-notification');

            if (!notification) return;

            // Set message
            if (success) {
                notification.style.background = 'rgba(16, 185, 129, 0.95)';
                notification.innerHTML = `
            <span style="margin-right: 8px;">🎵</span>
            ${trackName}
            ${language ? `<span style="opacity: 0.8; font-size: 12px; margin-left: 8px;">(${language})</span>` : ''}
        `;
            } else {
                notification.style.background = 'rgba(239, 68, 68, 0.95)';
                notification.innerHTML = `
            <span style="margin-right: 8px;">⚠️</span>
            ${trackName}
        `;
            }

            // Show notification
            notification.classList.add('show');

            // Auto-hide after 2.5 seconds
            setTimeout(() => {
                notification.classList.remove('show');
            }, 2500);
        }
        async function selectSubtitle(index) {
            if (!window.videoDecryptor) return;

            document.getElementById('subtitle-menu').classList.remove('show');
            await window.videoDecryptor.loadSubtitle(index);
            updateSubtitleMenuActive(index);
        }

        function disableSubtitles() {
            if (!window.videoDecryptor) return;

            document.getElementById('subtitle-menu').classList.remove('show');
            window.videoDecryptor.disableSubtitles();
            updateSubtitleMenuActive(-1);
        }

        function updateQualityMenuActive(activeQuality) {
            document.querySelectorAll('#quality-menu .menu-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.quality === activeQuality) {
                    item.classList.add('active');
                }
            });
        }

        function updateAudioMenuActive(activeIndex) {
            document.querySelectorAll('#audio-menu .menu-item').forEach((item, idx) => {
                item.classList.remove('active');
                if (parseInt(item.dataset.index) === activeIndex) {
                    item.classList.add('active');
                }
            });
        }

        function initializeAudioControls() {
            // Add keyboard shortcut
            document.addEventListener('keydown', (e) => {
                // Ignore if typing in input
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                    return;
                }

                // 'A' key to cycle audio tracks
                if (e.key.toLowerCase() === 'a' && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    cycleAudioTrack();
                }
            });

            console.log('🎵 Audio controls initialized');
        }

        function updateSubtitleMenuActive(activeIndex) {
            document.querySelectorAll('#subtitle-menu .menu-item').forEach(item => {
                item.classList.remove('active');
                if (parseInt(item.dataset.index) === activeIndex) {
                    item.classList.add('active');
                }
            });
        }

        function populateQualityMenu(qualities, currentQuality) {
            const menu = document.getElementById('quality-menu');
            menu.innerHTML = '';

            qualities.forEach(quality => {
                const item = document.createElement('div');
                item.className = 'menu-item';
                if (quality === currentQuality) {
                    item.classList.add('active');
                }
                item.dataset.quality = quality;
                item.textContent = quality;
                item.onclick = () => switchQuality(quality);
                menu.appendChild(item);
            });
        }

        function populateAudioMenu(tracks) {
            if (!tracks || tracks.length === 0) return;

            const menu = document.getElementById('audio-menu');
            const container = document.getElementById('audio-selector-container');

            // Show container
            container.style.display = 'block';

            // Clear menu
            menu.innerHTML = '';

            // Add each track
            tracks.forEach((track, index) => {
                const item = document.createElement('div');
                item.className = 'menu-item';

                // Mark first track as active
                if (index === 0) {
                    item.classList.add('active');
                }

                item.dataset.index = index;

                // Build track label
                const title = track.title || `Track ${index + 1}`;
                const lang = track.language && track.language !== 'und' ?
                    ` (${track.language})` :
                    '';

                item.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <span>${title}${lang}</span>
                ${index === 0 ? '<span style="opacity: 0.6; font-size: 11px;">DEFAULT</span>' : ''}
            </div>
        `;

                item.onclick = () => switchAudioTrack(index);

                menu.appendChild(item);
            });

            console.log(`📋 Populated audio menu with ${tracks.length} tracks`);
        }

        function populateSubtitleMenu(tracks) {
            if (!tracks || tracks.length === 0) return;

            const menu = document.getElementById('subtitle-menu');
            const container = document.getElementById('subtitle-selector-container');

            container.style.display = 'block';

            // Keep "Off" option
            menu.innerHTML = '<div class="menu-item active" onclick="disableSubtitles()">Off</div>';

            tracks.forEach((track, index) => {
                const item = document.createElement('div');
                item.className = 'menu-item';
                item.dataset.index = index;
                item.textContent = track.title || `${track.language} Subtitle`;
                item.onclick = () => selectSubtitle(index);
                menu.appendChild(item);
            });
        }

        // ============================================
        // EVENT LISTENERS - DOCUMENT
        // ============================================
        document.addEventListener('fullscreenchange', () => {
            const btn = document.getElementById('fullscreen-btn');
            btn.textContent = document.fullscreenElement ? '⛶' : '⛶';
        });

        document.addEventListener('DOMContentLoaded', initPlayer);

        window.addEventListener('beforeunload', (e) => {
            if (playerState.isPlaying) {
                e.preventDefault();
                e.returnValue = 'You are currently watching a video. Are you sure you want to leave?';
            }
        });
        window.addEventListener('audioTrackChanged', (event) => {
            const {
                from,
                to,
                track
            } = event.detail;

            console.log(`📢 Audio track changed: ${from} → ${to}`);
            console.log(`   Track: ${track.title} (${track.language})`);

            // Update any additional UI elements if needed
            const audioBtn = document.getElementById('audio-btn');
            if (audioBtn && track.language && track.language !== 'und') {
                audioBtn.title = `Audio: ${track.title} (${track.language})`;
            }
        });

        // Visibility change - pause when tab hidden
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && playerState.isPlaying) {
                videoPlayer.pause();
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            initializeAudioControls();

            // Display audio info after player loads
            setTimeout(() => {
                displayAudioTrackInfo();
            }, 2000);
        });

        window.debugAudioTracks = function() {
            if (!window.videoDecryptor) {
                console.log('❌ Video decryptor not initialized');
                return;
            }

            console.log('=== AUDIO TRACKS DEBUG ===');
            console.log('Total tracks:', window.videoDecryptor.audioTracks.length);
            console.log('Current track:', window.videoDecryptor.currentAudioTrack);
            console.table(window.videoDecryptor.audioTracks);
            console.log('========================');
        };

        // Close menus when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.quality-selector-container') &&
                !e.target.closest('.audio-selector-container') &&
                !e.target.closest('.subtitle-selector-container')) {
                document.getElementById('quality-menu').classList.remove('show');
                document.getElementById('audio-menu').classList.remove('show');
                document.getElementById('subtitle-menu').classList.remove('show');
            }
        });

        console.log('🎬 SecureStream Player loaded');
    </script>
</body>

</html>