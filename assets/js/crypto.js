/**
 * ============================================
 * SECURE DRM-LIKE VIDEO DECRYPTOR (CLIENT)
 * ============================================
 * 
 * Security Features:
 * - Ephemeral keys that expire every 30 seconds
 * - Session-bound playback
 * - No master key ever reaches client
 * - Watermark removal (invisible to user)
 * - Anti-tampering validation
 * - Heartbeat mechanism
 */

class SecureVideoDecryptor {
    constructor() {
        // Core properties
        this.videoId = null;
        this.videoInfo = null;
        this.chunkCount = 0;
        this.currentChunk = 0;

        // Multi-quality support
        this.availableQualities = [];
        this.currentQuality = '720p';
        this.qualitySwitching = false;

        // Multi-audio support
        this.audioTracks = [];
        this.currentAudioTrack = 0;

        // Subtitle support
        this.subtitleTracks = [];
        this.currentSubtitle = -1;
        
        // DRM Session
        this.playbackSession = null;
        this.sessionToken = null;
        this.sessionExpiry = null;
        
        // MediaSource components
        this.mediaSource = null;
        this.videoBuffer = null;
        this.audioBuffer = null;
        
        // State management
        this.isInitialized = false;
        this.streamEnded = false;
        this.seeking = false;
        this.isDecrypting = false;
        
        // Chunk management
        this.chunkQueue = [];
        this.pendingChunks = new Set();
        this.ephemeralKeys = new Map(); // Cache for chunk keys
        
        // Performance optimization
        this.fetchController = null;
        this.abortSignal = null;
        this.chunkLoaderInterval = null;
        this.heartbeatInterval = null;
        this.lastBufferCleanup = 0;
        
        // Configuration
        this.config = {
            encryptionMethod: 'AES-CTR',
            maxQueueSize: 3,
            bufferAhead: 25,
            bufferBehind: 15,
            maxBuffer: 40,
            cleanupInterval: 10000,
            retryAttempts: 3,
            retryDelay: 1000,
            heartbeatInterval: 20000, // 20 seconds
            keyRefreshInterval: 25000 // 25 seconds
        };

        console.log('🔐 SecureVideoDecryptor created');

        // Heartbeat tracking
        this.heartbeatFailures = 0;
        this.maxHeartbeatFailures = 3;

    }

    // ================================================
    // INITIALIZATION WITH DRM SESSION
    // ================================================

    async init(videoId) {
        try {
            this.videoId = videoId;
            console.log('🔐 Initializing secure session for:', videoId);
            
            // Step 1: Create secure playback session
            const session = await this.createPlaybackSession(videoId);
            if (!session.success) {
                throw new Error('Failed to create playback session');
            }
            
            this.sessionToken = session.session_token;
            this.sessionExpiry = Date.now() + (session.expires_in * 1000);
            
            console.log('✅ Playback session created, expires in', session.expires_in, 'seconds');
            
            // Step 2: Fetch video info with qualities/tracks
            const videoInfo = await this.fetchVideoInfo();
            if (!videoInfo || !videoInfo.chunk_count) {
                throw new Error('Failed to get video information');
            }
            
            this.videoInfo = videoInfo;
            this.chunkCount = videoInfo.chunk_count;

            // Get available qualities and tracks
            const tracksInfo = await this.fetchAvailableTracks();
            this.availableQualities = tracksInfo.qualities || ['720p'];
            this.audioTracks = tracksInfo.audio_tracks || [];
            this.subtitleTracks = tracksInfo.subtitle_tracks || [];
            
            // Auto-select best quality
            this.currentQuality = this.selectBestQuality();
            
            console.log(`📊 Video: ${this.chunkCount} chunks, Qualities: ${this.availableQualities.join(', ')}`);
            console.log(`🎵 Audio tracks: ${this.audioTracks.length}, 📝 Subtitles: ${this.subtitleTracks.length}`);
            
            // Start heartbeat
            this.startHeartbeat();
            
            this.isInitialized = true;
            console.log('✅ Enhanced decryptor initialized');
            
            return true;
        } catch (error) {
            console.error('❌ Failed to initialize:', error);
            this.isInitialized = false;
            throw error;
        }
    }

    async createPlaybackSession(videoId) {
        const response = await fetch('/stream/api/init_playback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `video_id=${encodeURIComponent(videoId)}`
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return await response.json();
    }
    async fetchAvailableTracks() {
        const response = await fetch(`/stream/api/get_qualities.php?video_id=${this.videoId}`);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    }
    selectBestQuality() {
        // Auto-select based on screen size
        const height = window.screen.height;
        
        if (this.availableQualities.includes('1080p') && height >= 1080) {
            return '1080p';
        } else if (this.availableQualities.includes('720p') && height >= 720) {
            return '720p';
        } else if (this.availableQualities.includes('480p')) {
            return '480p';
        }
        
        return this.availableQualities[0] || '720p';
    }

    async switchQuality(newQuality) {
        if (!this.availableQualities.includes(newQuality)) {
            console.warn('Quality not available:', newQuality);
            return false;
        }

        if (this.qualitySwitching || newQuality === this.currentQuality) {
            return false;
        }

        console.log(`🔄 Switching quality: ${this.currentQuality} → ${newQuality}`);

        this.qualitySwitching = true;
        const videoEl = document.getElementById('secure-video');
        const currentTime = videoEl.currentTime;
        const wasPlaying = !videoEl.paused;

        try {
            // Stop current loading
            if (this.chunkLoaderInterval) {
                clearInterval(this.chunkLoaderInterval);
            }

            if (this.fetchController) {
                this.fetchController.abort();
                this.fetchController = new AbortController();
                this.abortSignal = this.fetchController.signal;
            }

            // Clear queues
            this.chunkQueue = [];
            this.pendingChunks.clear();

            // Update quality
            const oldQuality = this.currentQuality;
            this.currentQuality = newQuality;

            // Clear buffers
            await this.clearAllBuffers();

            // Calculate chunk for current time
            const chunkDuration = this.videoInfo.chunk_size_seconds;
            this.currentChunk = Math.floor(currentTime / chunkDuration);

            // Restart loader
            this.startChunkLoader();

            // Wait for buffer
            await this.waitForBuffer(currentTime, 2.0);

            // Seek to position
            videoEl.currentTime = currentTime;

            if (wasPlaying) {
                await videoEl.play();
            }

            console.log(`✅ Quality switched to ${newQuality}`);
            
            // Notify UI
            this.dispatchEvent('qualityChanged', { 
                from: oldQuality, 
                to: newQuality 
            });

            return true;

        } catch (error) {
            console.error('Quality switch failed:', error);
            return false;
        } finally {
            this.qualitySwitching = false;
        }
    }

    async switchAudioTrackWithRetry(trackIndex, retries = 2) {
        let lastError = null;

        for (let attempt = 0; attempt <= retries; attempt++) {
            try {
                if (attempt > 0) {
                    console.log(`🔄 Retry ${attempt}/${retries} for audio track switch`);
                    await new Promise(resolve => setTimeout(resolve, 1000));
                }

                const success = await this.switchAudioTrack(trackIndex);

                if (success) {
                    return true;
                }

                lastError = new Error('Audio track switch returned false');

            } catch (error) {
                lastError = error;
                console.error(`❌ Audio track switch attempt ${attempt + 1} failed:`, error);
            }
        }

        console.error('❌ Audio track switch failed after all retries');

        // Show user-friendly error
        this.showAudioSwitchError(lastError);

        return false;
    }

    async validateAudioTrackAvailable(trackIndex) {
        try {
            // Try to fetch the first chunk of this audio track
            const testUrl = `/stream/api/stream_chunk_secure.php?` +
                `video_id=${encodeURIComponent(this.videoId)}` +
                `&track=audio` +
                `&index=0` +
                `&quality=${this.currentQuality}` +
                `&audio_track=${trackIndex}` +
                `&session_token=${encodeURIComponent(this.sessionToken)}`;

            const response = await fetch(testUrl, {
                method: 'HEAD', // Just check if it exists
                headers: { 'Cache-Control': 'no-cache' }
            });

            return response.ok;

        } catch (error) {
            console.warn('Audio track validation failed:', error);
            return false;
        }
    }


    showAudioSwitchError(error) {
        const message = this.getAudioErrorMessage(error);

        // Create error notification
        const notification = document.createElement('div');
        notification.style.cssText = `
        position: fixed;
        top: 100px;
        left: 50%;
        transform: translateX(-50%);
        background: rgba(239, 68, 68, 0.95);
        color: white;
        padding: 15px 25px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        z-index: 10010;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    `;
        notification.textContent = message;

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.opacity = '0';
            notification.style.transition = 'opacity 0.3s';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    /**
     * Get user-friendly error message
     */
    getAudioErrorMessage(error) {
        const errorString = error?.message || error?.toString() || '';

        if (errorString.includes('404') || errorString.includes('not found')) {
            return '⚠️ Audio track not available in this quality';
        }

        if (errorString.includes('network') || errorString.includes('fetch')) {
            return '⚠️ Network error. Please check your connection';
        }

        if (errorString.includes('decrypt')) {
            return '⚠️ Decryption error. Please refresh the page';
        }

        return '⚠️ Failed to switch audio track. Please try again';
    }
    async switchAudioTrack(trackIndex) {
        if (trackIndex < 0 || trackIndex >= this.audioTracks.length) {
            console.warn('Invalid audio track index:', trackIndex);
            return false;
        }

        if (this.currentAudioTrack === trackIndex) {
            console.log('Audio track already active');
            return true;
        }

        if (this.qualitySwitching || this.seeking) {
            console.warn('Cannot switch audio while quality switching or seeking');
            return false;
        }

        // Validate track is available
        console.log(`🔍 Validating audio track ${trackIndex} availability...`);
        const isAvailable = await this.validateAudioTrackAvailable(trackIndex);

        if (!isAvailable) {
            console.warn(`Audio track ${trackIndex} not available in quality ${this.currentQuality}`);
            this.showAudioSwitchError(new Error('Audio track not available in this quality'));
            return false;
        }

        console.log(`🎵 Switching audio track: ${this.audioTracks[this.currentAudioTrack].title} → ${this.audioTracks[trackIndex].title}`);

        const videoEl = document.getElementById('secure-video');
        const currentTime = videoEl.currentTime;
        const wasPlaying = !videoEl.paused;

        try {
            this.qualitySwitching = true;
            this.showBuffering();

            // Stop current chunk loading
            if (this.chunkLoaderInterval) {
                clearInterval(this.chunkLoaderInterval);
            }

            if (this.fetchController) {
                this.fetchController.abort();
                this.fetchController = new AbortController();
                this.abortSignal = this.fetchController.signal;
            }

            // Clear queues
            this.chunkQueue = [];
            this.pendingChunks.clear();

            // Update audio track
            const oldTrack = this.currentAudioTrack;
            this.currentAudioTrack = trackIndex;

            // Clear only audio buffer
            await this.clearAudioBuffer();

            // Calculate current chunk
            const chunkDuration = this.videoInfo.chunk_size_seconds;
            this.currentChunk = Math.floor(currentTime / chunkDuration);

            // Restart chunk loader with new audio track
            this.startChunkLoader();

            // Wait for audio buffer to fill
            await this.waitForAudioBuffer(currentTime, 2.0);

            // Resume playback if it was playing
            if (wasPlaying) {
                await videoEl.play();
            }

            this.hideBuffering();

            console.log(`✅ Audio track switched to: ${this.audioTracks[trackIndex].title}`);

            // Notify UI
            this.dispatchEvent('audioTrackChanged', {
                from: oldTrack,
                to: trackIndex,
                track: this.audioTracks[trackIndex]
            });

            return true;

        } catch (error) {
            console.error('❌ Audio track switch failed:', error);

            // Attempt recovery - revert to old track
            console.log('🔄 Attempting recovery...');

            this.currentAudioTrack = oldTrack;

            // Clear queues again
            this.chunkQueue = [];
            this.pendingChunks.clear();

            // Restart with old track
            this.startChunkLoader();

            this.hideBuffering();

            throw error; // Re-throw for retry logic

        } finally {
            this.qualitySwitching = false;
        }
    }

    showBuffering() {
        const indicator = document.getElementById('buffer-indicator');
        if (indicator) {
            indicator.classList.add('show');
        }
    }

    hideBuffering() {
        const indicator = document.getElementById('buffer-indicator');
        if (indicator) {
            indicator.classList.remove('show');
        }
    }

    getStatus() {
        return {
            initialized: this.isInitialized,
            videoId: this.videoId,
            sessionToken: this.sessionToken ? 'Active' : 'None',
            sessionExpiry: this.sessionExpiry ? new Date(this.sessionExpiry) : null,
            currentChunk: this.currentChunk,
            totalChunks: this.chunkCount,
            currentQuality: this.currentQuality,
            availableQualities: this.availableQualities,
            currentAudioTrack: this.currentAudioTrack,
            totalAudioTracks: this.audioTracks.length,
            audioTrackInfo: this.audioTracks[this.currentAudioTrack],
            queueSize: this.chunkQueue.length,
            pendingChunks: this.pendingChunks.size,
            cachedKeys: this.ephemeralKeys.size,
            streamEnded: this.streamEnded,
            seeking: this.seeking,
            qualitySwitching: this.qualitySwitching,
            mediaSourceState: this.mediaSource?.readyState
        };
    }

    async clearAudioBuffer() {
        if (!this.audioBuffer || this.audioBuffer.updating) {
            return;
        }

        console.log('🧹 Clearing audio buffer');

        try {
            if (this.audioBuffer.buffered.length > 0) {
                const start = this.audioBuffer.buffered.start(0);
                const end = this.audioBuffer.buffered.end(this.audioBuffer.buffered.length - 1);

                this.audioBuffer.remove(start, end);
                await this.waitForUpdate(this.audioBuffer);
            }
        } catch (error) {
            console.warn('Audio buffer clear warning:', error);
        }
    }
    async waitForAudioBuffer(time, duration = 2.0) {
        const videoEl = document.getElementById('secure-video');
        const targetEnd = time + duration;

        return new Promise((resolve) => {
            const startTime = Date.now();
            const timeout = 10000; // 10 second timeout

            const check = () => {
                if (Date.now() - startTime > timeout) {
                    console.warn('Audio buffer wait timeout');
                    return resolve();
                }

                // Check if audio is buffered
                if (this.audioBuffer && this.audioBuffer.buffered.length > 0) {
                    for (let i = 0; i < this.audioBuffer.buffered.length; i++) {
                        if (this.audioBuffer.buffered.start(i) <= time &&
                            this.audioBuffer.buffered.end(i) >= targetEnd) {
                            console.log('✅ Audio buffer ready');
                            return resolve();
                        }
                    }
                }

                requestAnimationFrame(check);
            };

            check();
        });
    }


    async loadSubtitle(trackIndex) {
        if (trackIndex < 0 || trackIndex >= this.subtitleTracks.length) {
            return false;
        }

        const track = this.subtitleTracks[trackIndex];
        const url = `/stream/api/get_subtitle.php?video_id=${this.videoId}&index=${trackIndex}`;

        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Failed to load subtitle');

            const vttText = await response.text();
            
            // Create subtitle track element
            const videoEl = document.getElementById('secure-video');
            
            // Remove existing subtitle tracks
            Array.from(videoEl.textTracks).forEach(t => {
                if (t.kind === 'subtitles') {
                    t.mode = 'disabled';
                }
            });

            // Add new track
            const trackEl = document.createElement('track');
            trackEl.kind = 'subtitles';
            trackEl.label = track.title;
            trackEl.srclang = track.language;
            trackEl.src = 'data:text/vtt;base64,' + btoa(vttText);
            trackEl.default = true;

            videoEl.appendChild(trackEl);
            trackEl.track.mode = 'showing';

            this.currentSubtitle = trackIndex;
            
            console.log(`📝 Loaded subtitle: ${track.title}`);
            
            this.dispatchEvent('subtitleLoaded', { 
                index: trackIndex,
                track: track
            });

            return true;

        } catch (error) {
            console.error('Failed to load subtitle:', error);
            return false;
        }
    }

    disableSubtitles() {
        const videoEl = document.getElementById('secure-video');
        Array.from(videoEl.textTracks).forEach(t => {
            t.mode = 'disabled';
        });
        
        this.currentSubtitle = -1;
        this.dispatchEvent('subtitleDisabled', {});
    }
    dispatchEvent(eventName, detail) {
        const event = new CustomEvent(eventName, { detail });
        window.dispatchEvent(event);
    }

    async fetchVideoInfo() {
        const response = await fetch(`/stream/api/video_info.php?id=${this.videoId}`, {
            credentials: 'same-origin',
            headers: { 'Cache-Control': 'no-cache' }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return await response.json();
    }

    // ================================================
    // EPHEMERAL KEY MANAGEMENT
    // ================================================

    async getEphemeralKey(chunkIndex) {
        // Check if we have a valid cached key
        const cached = this.ephemeralKeys.get(chunkIndex);
        if (cached && Date.now() < cached.validUntil * 1000) {
            return cached;
        }

        console.log(`🔑 Requesting ephemeral key for chunk ${chunkIndex}`);

        try {
            const response = await fetch(
                `/stream/api/get_chunk_key.php?` +
                `video_id=${encodeURIComponent(this.videoId)}` +
                `&chunk_index=${chunkIndex}` +
                `&session_token=${encodeURIComponent(this.sessionToken)}`
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const keyData = await response.json();
            
            if (!keyData.success) {
                throw new Error(keyData.error || 'Failed to get ephemeral key');
            }

            // Cache the key
            this.ephemeralKeys.set(chunkIndex, keyData);

            // Auto-cleanup expired keys
            setTimeout(() => {
                this.ephemeralKeys.delete(chunkIndex);
            }, (keyData.valid_until - Math.floor(Date.now() / 1000)) * 1000);

            return keyData;

        } catch (error) {
            console.error(`Failed to get ephemeral key for chunk ${chunkIndex}:`, error);
            throw error;
        }
    }

    // ================================================
    // HEARTBEAT TO KEEP SESSION ALIVE
    // ================================================

    startHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
        }
        this.heartbeatFailures = 0;

        this.heartbeatInterval = setInterval(async () => {
            try {
                const videoEl = document.getElementById('secure-video');
                const currentTime = videoEl ? videoEl.currentTime : 0;

                const response = await fetch('/stream/api/playback_heartbeat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `token=${encodeURIComponent(this.sessionToken)}&current_time=${currentTime}`
                });

                if (!response.ok) {
                    console.warn('Heartbeat failed, session may expire');
                    return;
                }

                const data = await response.json();
                if (data.success) {
                    console.log('💓 Heartbeat OK, session time remaining:', data.time_remaining, 's');
                } else {
                    console.error('Session invalid:', data.error);
                    this.handleSessionExpired();
                }
                this.heartbeatFailures = 0;

            } catch (error) {
                console.error('Heartbeat error:', error);
                this.heartbeatFailures++;
                if (this.heartbeatFailures >= this.maxHeartbeatFailures) {
                    console.error('Heartbeat stopped: server unreachable');
                    this.stopHeartbeat();
                    this.handleSessionExpired();
                }
            }
        }, this.config.heartbeatInterval);
    }

    stopHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    }


    handleSessionExpired() {

        console.warn('Playback session expired');

        this.stopHeartbeat();

        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }
        if(this.heartbeatInterval){
            clearInterval(this.heartbeatInterval)
        }

        if (this.fetchController) {
            this.fetchController.abort();
        }
        clearInterval(this.heartbeatInterval);
        clearInterval(this.chunkLoaderInterval);
        
        alert('Your playback session has expired. Please refresh the page to continue.');
        
        // Pause playback
        const videoEl = document.getElementById('secure-video');
        if (videoEl) {
            videoEl.pause();
        }
    }

    // ================================================
    // SECURE CHUNK FETCHING
    // ================================================

    async fetchChunk(track, index) {
        // Determine audio stream ID based on selected track
        let audioStreamId = 1; // Default audio stream

        if (track === 'audio' && this.currentAudioTrack > 0) {
            // Map audio track index to stream ID
            // Stream IDs: 0 = video, 1 = audio_track_0, 2 = audio_track_1, etc.
            audioStreamId = 1 + this.currentAudioTrack;
        }

        const url = `/stream/api/stream_chunk_secure.php?` +
            `video_id=${encodeURIComponent(this.videoId)}` +
            `&track=${track}` +
            `&index=${index}` +
            `&quality=${this.currentQuality}` +
            `&audio_track=${this.currentAudioTrack}` +
            `&session_token=${encodeURIComponent(this.sessionToken)}`;

        try {
            const response = await fetch(url, {
                signal: this.abortSignal,
                headers: { 'Cache-Control': 'no-cache' }
            });

            if (!response.ok) {
                if (response.status === 404) {
                    console.warn(`Chunk ${track}:${index} not found in ${this.currentQuality}, audio track ${this.currentAudioTrack}`);
                    return null;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            const encryptedData = await response.arrayBuffer();

            // Remove watermark
            const watermarkSize = 8;
            const actualData = encryptedData.slice(watermarkSize);

            return actualData;

        } catch (error) {
            if (error.name === 'AbortError') {
                console.log(`Fetch aborted for ${track}:${index}`);
                return null;
            }
            throw error;
        }
    }

    // ================================================
    // DECRYPTION WITH EPHEMERAL KEYS
    // ================================================

    async decryptChunk(encryptedData, chunkIndex) {
        if (!encryptedData || encryptedData.byteLength === 0) {
            throw new Error(`Empty chunk data at index ${chunkIndex}`);
        }
        
        try {
            // Get ephemeral key for this chunk
            const keyData = await this.getEphemeralKey(chunkIndex);
            
            // Import ephemeral key
            const ephemeralKey = await this.importKey(keyData.key);
            const ephemeralIV = this.base64ToArrayBuffer(keyData.iv);
            
            // Decrypt with ephemeral key
            const decrypted = await window.crypto.subtle.decrypt(
                {
                    name: this.config.encryptionMethod,
                    counter: ephemeralIV,
                    length: 64
                },
                ephemeralKey,
                encryptedData
            );

            console.log(`🔓 Chunk ${chunkIndex} decrypted with ephemeral key`);
            return decrypted;
            
        } catch (error) {
            console.error(`Failed to decrypt chunk ${chunkIndex}:`, error);
            throw error;
        }
    }

    async importKey(base64Key) {
        const keyBuffer = this.base64ToArrayBuffer(base64Key);
        
        const importedKey = await window.crypto.subtle.importKey(
            'raw',
            keyBuffer,
            { 
                name: this.config.encryptionMethod,
                length: 256
            },
            false,
            ['decrypt']
        );
        
        return importedKey;
    }

    // ================================================
    // REST OF THE METHODS (SAME AS BEFORE)
    // ================================================

    async prepareMediaSource(videoElement) {
        if (!this.isInitialized) {
            throw new Error('Decryptor not initialized');
        }

        console.log('🎥 Preparing MediaSource');

        this.mediaSource = new MediaSource();
        const mediaSourceURL = URL.createObjectURL(this.mediaSource);
        videoElement.src = mediaSourceURL;

        this.fetchController = new AbortController();
        this.abortSignal = this.fetchController.signal;

        return new Promise((resolve, reject) => {
            this.mediaSource.addEventListener('sourceopen', async () => {
                try {
                    await this.initSourceBuffers();
                    await this.appendInitSegments();
                    this.startChunkLoader();
                    
                    console.log('✅ MediaSource ready');
                    resolve();
                } catch (err) {
                    reject(err);
                }
            }, { once: true });
        });
    }

    async initSourceBuffers() {
        const videoCodec = 'video/mp4; codecs="avc1.64001e"';
        const audioCodec = 'audio/mp4; codecs="mp4a.40.2"';

        if (!MediaSource.isTypeSupported(videoCodec)) {
            throw new Error('Video codec not supported');
        }
        if (!MediaSource.isTypeSupported(audioCodec)) {
            throw new Error('Audio codec not supported');
        }

        this.videoBuffer = this.mediaSource.addSourceBuffer(videoCodec);
        this.audioBuffer = this.mediaSource.addSourceBuffer(audioCodec);

        this.videoBuffer.addEventListener('updateend', () => this.onBufferUpdateEnd());
        this.audioBuffer.addEventListener('updateend', () => this.onBufferUpdateEnd());

        console.log('📼 SourceBuffers created');
    }

    async appendInitSegments() {
        console.log('🎬 Fetching init segments...');

        const [vInit, aInit] = await Promise.all([
            this.fetchInitSegment('video'),
            this.fetchInitSegment('audio')
        ]);

        this.videoBuffer.appendBuffer(vInit);
        await this.waitForUpdate(this.videoBuffer);

        this.audioBuffer.appendBuffer(aInit);
        await this.waitForUpdate(this.audioBuffer);

        const totalDuration = this.videoInfo.chunk_count * this.videoInfo.chunk_size_seconds;
        this.mediaSource.duration = totalDuration;

        console.log(`✅ Init segments appended, duration: ${totalDuration}s`);
    }

    async fetchInitSegment(track) {
        const url = `/stream/api/init_segment.php?video_id=${this.videoId}&track=${track}`;
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`Failed to fetch ${track} init segment`);
        }
        
        return response.arrayBuffer();
    }

    startChunkLoader() {
        console.log('⚙️ Starting chunk loader');

        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }

        const videoEl = document.getElementById('secure-video');

        this.chunkLoaderInterval = setInterval(() => {
            const bufferedAhead = this.getBufferedAhead(videoEl);
            const needsMoreChunks = bufferedAhead < this.config.bufferAhead;
            const hasCapacity = this.chunkQueue.length < this.config.maxQueueSize;
            const hasMoreChunks = this.currentChunk < this.chunkCount;

            if ((this.seeking || needsMoreChunks) && hasCapacity && hasMoreChunks) {
                this.loadNextChunks();
            }

            if (Date.now() - this.lastBufferCleanup > this.config.cleanupInterval) {
                this.cleanupOldBuffers(videoEl);
                this.lastBufferCleanup = Date.now();
            }

        }, 300);

        this.loadNextChunks();
    }

    async loadNextChunks() {
        if (this.pendingChunks.has(this.currentChunk)) {
            return;
        }

        const index = this.currentChunk;

        if (index >= this.chunkCount) {
            if (!this.streamEnded &&
                this.chunkQueue.length === 0 &&
                this.mediaSource &&
                this.mediaSource.readyState === 'open') {

                console.log('🏁 All chunks loaded');
                clearInterval(this.chunkLoaderInterval);
                this.mediaSource.endOfStream();
                this.streamEnded = true;
            }
            return;
        }

        try {
            this.pendingChunks.add(index);

            // Fetch video and audio chunks
            // Audio chunk uses current audio track
            const [vEnc, aEnc] = await Promise.all([
                this.fetchChunk('video', index),
                this.fetchChunk('audio', index)
            ]);

            if (!vEnc || !aEnc) {
                this.pendingChunks.delete(index);
                return;
            }

            this.chunkQueue.push({
                index,
                video: vEnc,
                audio: aEnc
            });

            this.currentChunk++;
            this.pendingChunks.delete(index);

            if (!this.isDecrypting) {
                this.processNextChunk();
            }

        } catch (error) {
            console.error(`Failed to load chunk ${index}:`, error);
            this.pendingChunks.delete(index);
        }
    }

    async processNextChunk() {
        if (this.isDecrypting ||
            this.chunkQueue.length === 0 ||
            !this.mediaSource ||
            this.mediaSource.readyState !== 'open') {
            return;
        }

        if (this.videoBuffer.updating || this.audioBuffer.updating) {
            return;
        }

        const videoEl = document.getElementById('secure-video');
        const bufferedAhead = this.getBufferedAhead(videoEl);

        if (!this.seeking && 
            !videoEl.paused && 
            bufferedAhead > this.config.maxBuffer) {
            return;
        }

        this.isDecrypting = true;

        try {
            const chunk = this.chunkQueue.shift();

            const [vDec, aDec] = await Promise.all([
                this.decryptChunk(chunk.video, chunk.index),
                this.decryptChunk(chunk.audio, chunk.index)
            ]);

            await this.cleanupOldBuffers(videoEl);

            this.videoBuffer.appendBuffer(vDec);
            await this.waitForUpdate(this.videoBuffer);

            this.audioBuffer.appendBuffer(aDec);
            await this.waitForUpdate(this.audioBuffer);

            console.log(`✅ Chunk ${chunk.index} appended`);

        } catch (error) {
            console.error('Failed to process chunk:', error);
        } finally {
            this.isDecrypting = false;

            if (this.chunkQueue.length > 0) {
                queueMicrotask(() => this.processNextChunk());
            }
        }
    }

    onBufferUpdateEnd() {
        if (!this.isDecrypting && this.chunkQueue.length > 0) {
            this.processNextChunk();
        }
    }

    async seek(timeSeconds) {
        const chunkDuration = this.videoInfo.chunk_size_seconds;
        const targetChunk = Math.floor(timeSeconds / chunkDuration);

        console.log(`⏭️ Seeking to ${timeSeconds.toFixed(2)}s (chunk ${targetChunk})`);

        this.seeking = true;

        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }

        if (this.fetchController) {
            this.fetchController.abort();
            this.fetchController = new AbortController();
            this.abortSignal = this.fetchController.signal;
        }

        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.currentChunk = targetChunk;
        this.isDecrypting = false;
        this.streamEnded = false;

        await this.clearAllBuffers();

        const video = document.getElementById('secure-video');
        video.currentTime = timeSeconds;

        this.startChunkLoader();
        await this.waitForBuffer(timeSeconds, 2.0);

        this.seeking = false;
        console.log('✅ Seek completed');
    }

    async waitForBuffer(time, duration = 1.0) {
        const video = document.getElementById('secure-video');
        const targetEnd = time + duration;

        return new Promise((resolve) => {
            const startTime = Date.now();
            const timeout = 10000;

            const check = () => {
                if (Date.now() - startTime > timeout) {
                    console.warn('Buffer wait timeout');
                    return resolve();
                }

                for (let i = 0; i < video.buffered.length; i++) {
                    if (video.buffered.start(i) <= time &&
                        video.buffered.end(i) >= targetEnd) {
                        return resolve();
                    }
                }

                requestAnimationFrame(check);
            };

            check();
        });
    }

    async cleanupOldBuffers(video) {
        if (!video || video.currentTime === 0) return;

        const removeEnd = video.currentTime - this.config.bufferBehind;
        if (removeEnd <= 0) return;

        try {
            if (this.videoBuffer.buffered.length && !this.videoBuffer.updating) {
                for (let i = 0; i < this.videoBuffer.buffered.length; i++) {
                    const start = this.videoBuffer.buffered.start(i);
                    const end = this.videoBuffer.buffered.end(i);
                    
                    if (end < removeEnd) {
                        this.videoBuffer.remove(start, end);
                        await this.waitForUpdate(this.videoBuffer);
                    } else if (start < removeEnd) {
                        this.videoBuffer.remove(start, Math.min(removeEnd, end));
                        await this.waitForUpdate(this.videoBuffer);
                    }
                }
            }

            if (this.audioBuffer.buffered.length && !this.audioBuffer.updating) {
                for (let i = 0; i < this.audioBuffer.buffered.length; i++) {
                    const start = this.audioBuffer.buffered.start(i);
                    const end = this.audioBuffer.buffered.end(i);
                    
                    if (end < removeEnd) {
                        this.audioBuffer.remove(start, end);
                        await this.waitForUpdate(this.audioBuffer);
                    } else if (start < removeEnd) {
                        this.audioBuffer.remove(start, Math.min(removeEnd, end));
                        await this.waitForUpdate(this.audioBuffer);
                    }
                }
            }

        } catch (error) {
            console.warn('Buffer cleanup warning:', error);
        }
    }

    async clearAllBuffers() {
        if (!this.videoBuffer || !this.audioBuffer) return;

        console.log('🧹 Clearing buffers');

        try {
            if (this.videoBuffer.buffered.length && !this.videoBuffer.updating) {
                const end = this.videoBuffer.buffered.end(this.videoBuffer.buffered.length - 1);
                this.videoBuffer.remove(0, end);
                await this.waitForUpdate(this.videoBuffer);
            }

            if (this.audioBuffer.buffered.length && !this.audioBuffer.updating) {
                const end = this.audioBuffer.buffered.end(this.audioBuffer.buffered.length - 1);
                this.audioBuffer.remove(0, end);
                await this.waitForUpdate(this.audioBuffer);
            }

        } catch (error) {
            console.warn('Buffer clear warning:', error);
        }
    }

    getBufferedAhead(video) {
        if (this.seeking) return 0;
        if (!video || !video.buffered.length) return 0;
        
        const currentTime = video.currentTime;
        const bufferedEnd = video.buffered.end(video.buffered.length - 1);
        
        return Math.max(0, bufferedEnd - currentTime);
    }

    async restart(videoElement) {
        console.log('🔄 Full restart');

        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }

        if (this.fetchController) {
            this.fetchController.abort();
        }

        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.currentChunk = 0;
        this.isDecrypting = false;
        this.streamEnded = false;
        this.seeking = false;

        if (this.mediaSource) {
            try {
                if (this.mediaSource.readyState === 'open') {
                    this.mediaSource.endOfStream();
                }
            } catch (e) {
                console.warn('MediaSource end warning:', e);
            }
        }

        this.mediaSource = null;
        this.videoBuffer = null;
        this.audioBuffer = null;

        await this.prepareMediaSource(videoElement);

        console.log('✅ Restart complete');
    }

    waitForUpdate(sourceBuffer) {
        return new Promise((resolve) => {
            if (!sourceBuffer.updating) {
                resolve();
            } else {
                sourceBuffer.addEventListener('updateend', resolve, { once: true });
            }
        });
    }

    base64ToArrayBuffer(base64) {
        try {
            const binaryString = atob(base64);
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            return bytes;
        } catch (error) {
            console.error('Failed to decode base64:', error);
            throw new Error('Invalid base64 string');
        }
    }

    cleanup() {
        console.log('🧹 Cleaning up');
        
        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }
        
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
        }
        
        if (this.fetchController) {
            this.fetchController.abort();
        }
        
        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.ephemeralKeys.clear();
        
        if (this.mediaSource) {
            try {
                if (this.mediaSource.readyState === 'open') {
                    this.mediaSource.endOfStream();
                }
            } catch (e) {}
        }
        
        this.videoBuffer = null;
        this.audioBuffer = null;
        this.isInitialized = false;
        
        console.log('✅ Cleanup complete');
    }

    getStatus() {
        return {
            initialized: this.isInitialized,
            videoId: this.videoId,
            sessionToken: this.sessionToken ? 'Active' : 'None',
            sessionExpiry: this.sessionExpiry ? new Date(this.sessionExpiry) : null,
            currentChunk: this.currentChunk,
            totalChunks: this.chunkCount,
            queueSize: this.chunkQueue.length,
            pendingChunks: this.pendingChunks.size,
            cachedKeys: this.ephemeralKeys.size,
            streamEnded: this.streamEnded,
            seeking: this.seeking,
            mediaSourceState: this.mediaSource?.readyState
        };
    }
}

// Replace old VideoDecryptor
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SecureVideoDecryptor;
} else {
    window.VideoDecryptor = SecureVideoDecryptor;
}

window.debugDecryptor = function() {
    if (window.videoDecryptor) {
        console.table(window.videoDecryptor.getStatus());
    } else {
        console.log('VideoDecryptor not initialized');
    }
};

window.addEventListener('beforeunload', () => {
    if (window.videoDecryptor) {
        window.videoDecryptor.cleanup();
    }
});


console.log('🔐 Secure DRM-like VideoDecryptor loaded');