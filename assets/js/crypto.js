/**
 * ============================================
 * ENHANCED SECURE VIDEO DECRYPTOR
 * ============================================
 * 
 * Features:
 * - Dynamic chunk handling per stream
 * - Multi-quality support with different chunk counts
 * - Multi-audio track support
 * - Mobile optimized
 * - Efficient buffering
 * - Automatic recovery
 */

class EnhancedVideoDecryptor {
    constructor() {
        // Core properties
        this.videoId = null;
        this.videoInfo = null;
        this.sessionToken = null;

        // Quality management
        this.availableQualities = [];
        this.currentQuality = '360p';
        this.qualityChunkMap = {};

        // Audio management
        this.audioTracks = [];
        this.currentAudioTrack = 0;
        this.audioStreamId = 1; // Default audio stream ID

        // Subtitle management
        this.subtitleTracks = [];
        this.currentSubtitle = -1;

        // Chunk management
        this.chunkMap = {
            video: 0,
            audio: []
        };
        this.currentChunk = {
            video: 0,
            audio: 0
        };
        this.maxChunks = 0;

        // MediaSource components
        this.mediaSource = null;
        this.videoBuffer = null;
        this.audioBuffer = null;
        this.sourceBuffers = {};

        // State management
        this.isInitialized = false;
        this.isPlaying = false;
        this.isSeeking = false;
        this.qualitySwitching = false;
        this.audioSwitching = false;
        this.streamEnded = false;

        // Queue management
        this.chunkQueue = [];
        this.pendingChunks = new Set();
        this.loadedChunks = new Set();
        this.failedChunks = new Set();

        // Performance optimization
        this.fetchController = null;
        this.loaderInterval = null;
        this.heartbeatInterval = null;
        this.bufferCleanupInterval = null;

        // Configuration
        this.config = {
            maxBufferAhead: 30, // seconds
            maxBufferBehind: 10, // seconds
            chunkLoadAhead: 3, // chunks
            maxRetries: 3,
            retryDelay: 1000,
            heartbeatInterval: 30000,
            bufferCheckInterval: 500,
            mobileBufferMultiplier: 1.5
        };

        // Statistics
        this.stats = {
            chunksLoaded: 0,
            chunksFailed: 0,
            bytesLoaded: 0,
            switchAttempts: 0,
            recoveryAttempts: 0
        };

        this.chunkFailures = {};

        // Mobile detection
        this.isMobile = this.detectMobile();

        console.log('🎬 EnhancedVideoDecryptor initialized');
    }

    // ================================================
    // INITIALIZATION
    // ================================================

    async init(videoId) {
        try {
            this.videoId = videoId;
            console.log('🔐 Initializing for video:', videoId);

            // Create playback session
            const session = await this.createPlaybackSession(videoId);
            if (!session.success) {
                throw new Error('Failed to create playback session');
            }

            this.sessionToken = session.session_token;

            // Fetch video info
            const videoInfo = await this.fetchVideoInfo();
            if (!videoInfo) {
                throw new Error('Failed to get video information');
            }

            this.videoInfo = videoInfo;

            // Fetch available tracks and qualities
            const tracksInfo = await this.fetchAvailableTracks();
            this.availableQualities = tracksInfo.qualities || ['360p'];
            this.audioTracks = tracksInfo.audio_tracks || [];
            this.subtitleTracks = tracksInfo.subtitle_tracks || [];
            this.chunkMap = videoInfo.chunk_map || {};

            // Initialize chunk map for current quality
            await this.updateChunkMapForQuality(this.currentQuality);

            // Calculate maximum chunks
            this.calculateMaxChunks();

            // Auto-select best quality
            this.currentQuality = this.selectBestQuality();

            console.log('📊 Video Info:', {
                qualities: this.availableQualities,
                audioTracks: this.audioTracks.length,
                maxChunks: this.maxChunks,
                currentQuality: this.currentQuality
            });

            // Start heartbeat
            this.startHeartbeat();

            this.isInitialized = true;
            console.log('✅ Enhanced decryptor initialized');

            return true;

        } catch (error) {
            console.error('❌ Initialization failed:', error);
            this.isInitialized = false;
            throw error;
        }
    }

    async createPlaybackSession(videoId) {
        const response = await fetch('../api/init_playback.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `video_id=${encodeURIComponent(videoId)}&csrf_token=${encodeURIComponent(csrf_token)}`
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return await response.json();
    }

    async fetchVideoInfo() {
        const response = await fetch('../api/video_info.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${encodeURIComponent(this.videoId)}&csrf_token=${encodeURIComponent(csrf_token)}`
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return await response.json();
    }

    async fetchAvailableTracks() {
        const response = await fetch('../api/get_qualities.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `video_id=${encodeURIComponent(this.videoId)}&csrf_token=${encodeURIComponent(csrf_token)}`
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return await response.json();
    }

    // ================================================
    // CHUNK MANAGEMENT
    // ================================================

    async updateChunkMapForQuality(quality) {
        try {
            const response = await fetch(`../api/get_chunk_info.php?` +
                `video_id=${encodeURIComponent(this.videoId)}` +
                `&quality=${encodeURIComponent(quality)}` +
                `&csrf_token=${encodeURIComponent(csrf_token)}`);

            if (response.ok) {
                const data = await response.json();
                if (data.success) {
                    this.chunkMap = data.chunk_map;
                    return true;
                }
            }

            // Fallback: use info from videoInfo
            if (this.videoInfo && this.videoInfo.chunk_map) {
                this.chunkMap = this.videoInfo.chunk_map[quality] ||
                    this.videoInfo.chunk_map;
                return true;
            }

            return false;

        } catch (error) {
            console.warn('Failed to update chunk map:', error);
            return false;
        }
    }

    calculateMaxChunks() {
        let max = 0;

        // Check video chunks
        if (this.chunkMap.video && typeof this.chunkMap.video === 'number') {
            max = Math.max(max, this.chunkMap.video);
        }

        // Check audio chunks
        if (this.chunkMap.audio && Array.isArray(this.chunkMap.audio)) {
            this.chunkMap.audio.forEach(audioInfo => {
                if (audioInfo.count && typeof audioInfo.count === 'number') {
                    max = Math.max(max, audioInfo.count);
                }
            });
        }

        this.maxChunks = max;
        console.log('📈 Maximum chunks:', this.maxChunks);
    }

    getChunkCount(streamType, streamId = null) {
        if (streamType === 'video') {
            return this.chunkMap.video || 0;
        } else if (streamType === 'audio') {
            if (streamId !== null) {
                const audioInfo = this.chunkMap.audio.find(a => a.stream_id === streamId);
                return audioInfo ? audioInfo.count : 0;
            }
            // Default to first audio stream
            const firstAudio = this.chunkMap.audio[0];
            return firstAudio ? firstAudio.count : 0;
        }
        return 0;
    }

    // ================================================
    // MEDIASOURCE MANAGEMENT
    // ================================================

    async prepareMediaSource(videoElement) {
        if (!this.isInitialized) {
            throw new Error('Decryptor not initialized');
        }

        console.log('🎥 Preparing MediaSource');

        // Create new MediaSource
        this.mediaSource = new MediaSource();
        const mediaSourceURL = URL.createObjectURL(this.mediaSource);
        videoElement.src = mediaSourceURL;

        // Setup abort controller
        this.fetchController = new AbortController();

        return new Promise((resolve, reject) => {
            this.mediaSource.addEventListener('sourceopen', async () => {
                try {
                    await this.initSourceBuffers();
                    await this.appendInitSegments();
                    this.startChunkLoader();
                    this.startBufferMonitor();

                    console.log('✅ MediaSource ready');
                    resolve();
                } catch (error) {
                    reject(error);
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

        // Store buffers for easy access
        this.sourceBuffers = {
            video: this.videoBuffer,
            audio: this.audioBuffer
        };

        // Add event listeners
        ['video', 'audio'].forEach(type => {
            this.sourceBuffers[type].addEventListener('updateend', () => {
                this.onBufferUpdateEnd(type);
            });
            this.sourceBuffers[type].addEventListener('error', (e) => {
                console.error(`${type} buffer error:`, e);
            });
        });

        console.log('📼 SourceBuffers created');
    }

    async appendInitSegments() {
        console.log('🎬 Fetching init segments...');

        try {
            const [vInit, aInit] = await Promise.all([
                this.fetchInitSegment('video', this.currentQuality),
                this.fetchInitSegment('audio', this.currentQuality)
            ]);

            // Append video init
            this.videoBuffer.appendBuffer(vInit);
            await this.waitForBufferUpdate(this.videoBuffer);

            // Append audio init
            this.audioBuffer.appendBuffer(aInit);
            await this.waitForBufferUpdate(this.audioBuffer);

            // Set MediaSource duration
            const totalDuration = this.maxChunks * this.videoInfo.chunk_size_seconds;
            if (this.mediaSource.readyState === 'open') {
                this.mediaSource.duration = totalDuration;
            }

            console.log(`✅ Init segments appended, duration: ${totalDuration}s`);

        } catch (error) {
            console.error('Failed to append init segments:', error);
            throw error;
        }
    }

    async fetchInitSegment(track, quality, audioTrack = null) {
        let url = `../api/init_segment.php?` +
            `video_id=${encodeURIComponent(this.videoId)}` +
            `&track=${track}` +
            `&quality=${encodeURIComponent(quality)}` +
            `&csrf_token=${encodeURIComponent(csrf_token)}`;

        if (track === 'audio' && audioTrack !== null) {
            url += `&audio_track=${audioTrack}`;
        }

        const response = await fetch(url);
        if (!response.ok) {
            throw new Error(`Failed to fetch ${track} init segment`);
        }

        return response.arrayBuffer();
    }

    // ================================================
    // CHUNK LOADING & STREAMING
    // ================================================

    startChunkLoader() {
        if (this.loaderInterval) {
            clearInterval(this.loaderInterval);
        }

        const loadInterval = this.isMobile ? 300 : 200;

        this.loaderInterval = setInterval(() => {
            this.manageChunkLoading();
        }, loadInterval);

        console.log('⚙️ Chunk loader started');
    }

    async manageChunkLoading() {
        const videoElement = document.getElementById('secure-video');
        if (!videoElement) return;

        // Skip if buffers are updating or seeking
        if (this.isSeeking || this.qualitySwitching || this.audioSwitching) {
            return;
        }

        // Check if buffers are updating
        if (this.videoBuffer.updating || this.audioBuffer.updating) {
            return;
        }

        const currentTime = videoElement.currentTime;
        const chunkDuration = this.videoInfo.chunk_size_seconds;
        const currentChunk = Math.floor(currentTime / chunkDuration);

        // Calculate how many chunks ahead we need
        const bufferedAhead = this.getBufferedAhead(videoElement);
        const chunksAhead = Math.floor(bufferedAhead / chunkDuration);

        const targetChunksAhead = this.isMobile ?
            this.config.chunkLoadAhead * this.config.mobileBufferMultiplier :
            this.config.chunkLoadAhead;

        if (chunksAhead < targetChunksAhead) {
            // Load more chunks
            const chunksToLoad = targetChunksAhead - chunksAhead;

            for (let i = 0; i < chunksToLoad; i++) {
                const chunkIndex = currentChunk + i;

                // Check if chunk exists
                if (chunkIndex >= this.maxChunks) {
                    if (!this.streamEnded) {
                        this.endStream();
                    }
                    break;
                }

                // Skip if already in processing
                if (this.isChunkBeingProcessed(chunkIndex)) {
                    continue;
                }

                // Load the chunk
                this.loadChunk(chunkIndex);
            }
        }

        // Cleanup old buffers
        this.cleanupOldBuffers(videoElement);
    }

    isChunkBeingProcessed(chunkIndex) {
        return this.pendingChunks.has(chunkIndex) ||
            this.loadedChunks.has(chunkIndex) ||
            this.failedChunks.has(chunkIndex) ||
            this.chunkQueue.some(chunk => chunk.index === chunkIndex);
    }


    async handleChunkError(track, chunkIndex, error) {
        console.warn(`Chunk error ${track}[${chunkIndex}]:`, error.message);

        if (error.code === "CHUNK_NOT_FOUND" || error.message.includes('404')) {
            // Chunk doesn't exist, return null
            return null;
        }

        // For other errors, re-throw
        throw error;
    }


    markChunkAsSkipped(chunkIndex) {
        //this.failedChunks.add(chunkIndex);
        this.failedChunks.delete(chunkIndex);
        this.pendingChunks.delete(chunkIndex);

        // Also mark it as "loaded" so we don't keep trying
        this.loadedChunks.add(chunkIndex);
    }
    getChunkRetryCount(chunkIndex) {
        return this.chunkFailures[chunkIndex] || 0;
    }
    shouldSkipChunk(chunkIndex) {
        return this.getChunkRetryCount(chunkIndex) >= this.config.maxRetries;
    }

    resetChunkRetryCount(chunkIndex) {
        delete this.chunkFailures[chunkIndex];
    }

    recordChunkFailure(chunkIndex) {
        if (!this.chunkFailures[chunkIndex]) {
            this.chunkFailures[chunkIndex] = 0;
        }
        this.chunkFailures[chunkIndex]++;
    }



    skipMissingChunk(chunkIndex) {
        console.log(`⏭️ Skipping chunk ${chunkIndex}`);
        

        // Mark as loaded so we don't try again
        this.loadedChunks.add(chunkIndex);
        this.failedChunks.delete(chunkIndex);
        this.pendingChunks.delete(chunkIndex);

        // Auto-advance if player is stuck on this chunk
        const videoElement = document.getElementById('secure-video');
        if (videoElement) {
            const chunkDuration = this.videoInfo.chunk_size_seconds;
            const currentChunk = Math.floor(videoElement.currentTime / chunkDuration);

            if (currentChunk === chunkIndex && videoElement.paused) {
                // Jump to next chunk
                const nextTime = (chunkIndex + 1) * chunkDuration;
                videoElement.currentTime = nextTime;
                console.log(`⏩ Auto-advancing to ${nextTime}s`);
            }
        }
    }


    async loadChunk(chunkIndex) {
        if (chunkIndex >= this.maxChunks) return;

        // Skip if already loaded or being processed
        if (this.isChunkBeingProcessed(chunkIndex)) {
            return;
        }

        // Check if this chunk should be skipped (failed too many times)
        if (this.shouldSkipChunk(chunkIndex)) {
            console.log(`⏭️ Skipping chunk ${chunkIndex} (max retries exceeded)`);
            this.markChunkAsSkipped(chunkIndex);
            return;
        }

        this.pendingChunks.add(chunkIndex);

        try {
            const [videoChunk, audioChunk] = await Promise.all([
                this.fetchAndDecryptChunk('video', chunkIndex).catch(err => this.handleChunkError('video', chunkIndex, err)),
                this.fetchAndDecryptChunk('audio', chunkIndex).catch(err => this.handleChunkError('audio', chunkIndex, err))
            ]);

            // Check if both chunks failed
            if (!videoChunk && !audioChunk) {
              console.log(`➡️ Both chunks missing for ${chunkIndex}, skipping`);
              this.skipMissingChunk(chunkIndex);
              
              return;
            }

            // Skip if either chunk is missing (better to skip than have mismatched A/V)
            if (!videoChunk || !audioChunk) {
              console.warn(`⚠️ Partial chunk ${chunkIndex}, skipping`);
              this.skipMissingChunk(chunkIndex);
              
              return;
            }

            // Handle successful load
            this.chunkQueue.push({
              index: chunkIndex,
              video: videoChunk,
              audio: audioChunk,
            });

            this.stats.chunksLoaded++;
            this.loadedChunks.add(chunkIndex);
            this.failedChunks.delete(chunkIndex);

            // Reset retry count on success
            this.resetChunkRetryCount(chunkIndex);

            // Process queue if not busy
            if (!this.videoBuffer.updating && !this.audioBuffer.updating) {
                this.processChunkQueue();
            }

        } catch (error) {
            console.warn(`Failed to load chunk ${chunkIndex}:`, error.message);

            // Track failure
            this.recordChunkFailure(chunkIndex);

            if (
              error.message.includes("CHUNK_NOT_FOUND") ||
              error.message.includes("404") ||
              error.code === "CHUNK_NOT_FOUND"
            ) {
              console.log(`🗑️ Chunk ${chunkIndex} not found, skipping`);
              this.skipMissingChunk(chunkIndex);
            
              return;
            }

            

            // Retry logic
            const retryCount = this.getChunkRetryCount(chunkIndex);
            if (retryCount < this.config.maxRetries) {
                const delay = this.config.retryDelay * Math.pow(2, retryCount); // Exponential backoff
                console.log(`🔁 Retrying chunk ${chunkIndex} in ${delay}ms (attempt ${retryCount + 1})`);

                setTimeout(() => {
                    this.pendingChunks.delete(chunkIndex);
                    this.loadChunk(chunkIndex);
                }, delay);
            } else {
                console.log(`❌ Max retries exceeded for chunk ${chunkIndex}, skipping`);
                this.markChunkAsSkipped(chunkIndex);
            }

            this.stats.chunksFailed++;

        } finally {
            // Only remove from pending if not scheduled for retry
            const retryCount = this.getChunkRetryCount(chunkIndex);
            if (retryCount >= this.config.maxRetries) {
                this.pendingChunks.delete(chunkIndex);
            }
        }
    }


    async fetchAndDecryptChunk(track, chunkIndex) {
        // Build URL with all parameters
        const url = `../api/stream_chunk_secure.php?` +
            `video_id=${encodeURIComponent(this.videoId)}` +
            `&track=${track}` +
            `&index=${chunkIndex}` +
            `&quality=${encodeURIComponent(this.currentQuality)}` +
            `&audio_track=${this.currentAudioTrack}` +
            `&session_token=${encodeURIComponent(this.sessionToken)}`;

        const response = await fetch(url, {
            signal: this.fetchController.signal,
            headers: {
                'Cache-Control': 'no-cache',
                'Pragma': 'no-cache'
            }
        });

        if (!response.ok) {
            if (response.status === 404) {
                const error = new Error('Failed to fetch chunk');
                error.code = "CHUNK_NOT_FOUND";
                throw error;
            }
            throw new Error(`HTTP ${response.status}`);
        }

        const encryptedData = await response.arrayBuffer();

        // Check if response is empty (0 bytes)
        if (encryptedData.byteLength === 0) {
            return null;
        }

        // Remove watermark (first 8 bytes)
        const actualData = encryptedData.slice(8);

        // Get ephemeral key and decrypt
        const keyData = await this.getEphemeralKey(chunkIndex);
        const decryptedData = await this.decryptWithEphemeralKey(actualData, keyData);

        return decryptedData;
    }

    async processChunkQueue() {
        if (this.chunkQueue.length === 0) return;

        // Check if buffers are ready
        if (this.videoBuffer.updating || this.audioBuffer.updating) {
            // Try again later
            setTimeout(() => this.processChunkQueue(), 50);
            return;
        }

        const chunk = this.chunkQueue[0]; // Peek at first chunk

        try {
            // Check if we're trying to append a duplicate
            const bufferStart = this.videoBuffer.buffered.length > 0 ?
                this.videoBuffer.buffered.start(0) : 0;
            const chunkStartTime = chunk.index * this.videoInfo.chunk_size_seconds;

            if (chunkStartTime < bufferStart) {
                console.log(`⏭️ Skipping chunk ${chunk.index} (already appended)`);
                this.chunkQueue.shift();
                this.processChunkQueue();
                return;
            }

            // Append video chunk
            this.videoBuffer.appendBuffer(chunk.video);
            await this.waitForBufferUpdate(this.videoBuffer);

            // Append audio chunk
            this.audioBuffer.appendBuffer(chunk.audio);
            await this.waitForBufferUpdate(this.audioBuffer);

            console.log(`✅ Chunk ${chunk.index} appended`);

            // Remove from queue after successful append
            this.chunkQueue.shift();

        } catch (error) {
            console.error('Failed to append chunk:', error);

            // Check for specific errors
            if (error.name === 'InvalidStateError' ||
                error.message.includes('still processing')) {
                // Buffer is busy, try again later
                setTimeout(() => this.processChunkQueue(), 100);
                return;
            }

            // For other errors, remove the chunk to avoid infinite loop
            console.warn(`🗑️ Removing problematic chunk ${chunk.index} from queue`);
            this.chunkQueue.shift();
            this.recordChunkFailure(chunk.index);
        }
    }

    onBufferUpdateEnd(bufferType) {
        // Small delay before processing next chunk
        setTimeout(() => {
            if (this.chunkQueue.length > 0 &&
                !this.videoBuffer.updating &&
                !this.audioBuffer.updating) {
                this.processChunkQueue();
            }
        }, 50);
    }

    // ================================================
    // QUALITY SWITCHING
    // ================================================

    async switchQuality(newQuality) {
        if (!this.availableQualities.includes(newQuality) ||
            newQuality === this.currentQuality ||
            this.qualitySwitching) {
            return false;
        }

        console.log(`🔄 Switching quality: ${this.currentQuality} → ${newQuality}`);
        this.qualitySwitching = true;
        this.stats.switchAttempts++;

        const videoElement = document.getElementById('secure-video');
        const currentTime = videoElement.currentTime;
        const wasPlaying = !videoElement.paused;

        try {
            // Pause playback
            if (wasPlaying) {
                videoElement.pause();
            }

            // Stop current loading
            this.stopChunkLoader();

            // Clear buffers
            await this.clearBuffers();

            // Update quality
            const oldQuality = this.currentQuality;
            this.currentQuality = newQuality;

            // Update chunk map for new quality
            await this.updateChunkMapForQuality(newQuality);
            this.calculateMaxChunks();

            // Re-fetch init segments
            await this.appendInitSegments();

            // Calculate new chunk index
            const chunkDuration = this.videoInfo.chunk_size_seconds;
            const newChunkIndex = Math.floor(currentTime / chunkDuration);

            // Reset chunk tracking
            this.loadedChunks.clear();
            this.failedChunks.clear();
            this.pendingChunks.clear();
            this.chunkQueue = [];

            // Seek to approximate position
            videoElement.currentTime = currentTime;

            // Restart loader
            this.startChunkLoader();

            // Resume playback
            if (wasPlaying) {
                await videoElement.play();
            }

            console.log(`✅ Quality switched to ${newQuality}`);
            return true;

        } catch (error) {
            console.error('Quality switch failed:', error);
            // Revert to old quality
            this.currentQuality = oldQuality;
            return false;

        } finally {
            this.qualitySwitching = false;
        }
    }

    // ================================================
    // AUDIO TRACK SWITCHING
    // ================================================

    async switchAudioTrack(trackIndex) {
        if (trackIndex < 0 || trackIndex >= this.audioTracks.length ||
            trackIndex === this.currentAudioTrack ||
            this.audioSwitching) {
            return false;
        }

        console.log(`🎵 Switching audio track: ${this.currentAudioTrack} → ${trackIndex}`);
        this.audioSwitching = true;

        const videoElement = document.getElementById('secure-video');
        const currentTime = videoElement.currentTime;
        const wasPlaying = !videoElement.paused;

        try {
            // Pause playback
            if (wasPlaying) {
                videoElement.pause();
            }

            // Update audio track
            const oldTrack = this.currentAudioTrack;
            this.currentAudioTrack = trackIndex;

            // Clear audio buffer
            await this.clearAudioBuffer();

            // Re-fetch audio init segment
            const audioInit = await this.fetchInitSegment('audio', this.currentQuality, trackIndex);
            this.audioBuffer.appendBuffer(audioInit);
            await this.waitForBufferUpdate(this.audioBuffer);

            // Reset audio chunk tracking
            this.clearAudioChunkTracking();

            // Restart loader
            this.startChunkLoader();

            // Resume playback
            if (wasPlaying) {
                await videoElement.play();
            }

            console.log(`✅ Audio track switched to ${trackIndex}`);
            return true;

        } catch (error) {
            console.error('Audio track switch failed:', error);
            this.currentAudioTrack = oldTrack;
            return false;

        } finally {
            this.audioSwitching = false;
        }
    }

    // ================================================
    // BUFFER MANAGEMENT
    // ================================================

    getBufferedAhead(videoElement) {
        if (!videoElement.buffered.length) return 0;

        const currentTime = videoElement.currentTime;
        const bufferedEnd = videoElement.buffered.end(videoElement.buffered.length - 1);

        return Math.max(0, bufferedEnd - currentTime);
    }

    async cleanupOldBuffers(videoElement) {
        if (!videoElement || videoElement.currentTime === 0) return;

        const removeEnd = videoElement.currentTime - this.config.maxBufferBehind;
        if (removeEnd <= 0) return;

        try {
            // Clean video buffer
            if (this.videoBuffer.buffered.length && !this.videoBuffer.updating) {
                for (let i = 0; i < this.videoBuffer.buffered.length; i++) {
                    const start = this.videoBuffer.buffered.start(i);
                    const end = this.videoBuffer.buffered.end(i);

                    if (end < removeEnd) {
                        this.videoBuffer.remove(start, end);
                        await this.waitForBufferUpdate(this.videoBuffer);
                    } else if (start < removeEnd) {
                        this.videoBuffer.remove(start, Math.min(removeEnd, end));
                        await this.waitForBufferUpdate(this.videoBuffer);
                    }
                }
            }

            // Clean audio buffer
            if (this.audioBuffer.buffered.length && !this.audioBuffer.updating) {
                for (let i = 0; i < this.audioBuffer.buffered.length; i++) {
                    const start = this.audioBuffer.buffered.start(i);
                    const end = this.audioBuffer.buffered.end(i);

                    if (end < removeEnd) {
                        this.audioBuffer.remove(start, end);
                        await this.waitForBufferUpdate(this.audioBuffer);
                    } else if (start < removeEnd) {
                        this.audioBuffer.remove(start, Math.min(removeEnd, end));
                        await this.waitForBufferUpdate(this.audioBuffer);
                    }
                }
            }

        } catch (error) {
            console.warn('Buffer cleanup warning:', error);
        }
    }

    startBufferMonitor() {
        if (this.bufferCleanupInterval) {
            clearInterval(this.bufferCleanupInterval);
        }

        this.bufferCleanupInterval = setInterval(() => {
            const videoElement = document.getElementById('secure-video');
            if (videoElement) {
                this.cleanupOldBuffers(videoElement);
            }
        }, this.config.bufferCheckInterval);
    }


    async clearBuffersIfNeeded(timeSeconds) {
        const videoElement = document.getElementById('secure-video');

        // Check if time is within current buffer
        for (let i = 0; i < videoElement.buffered.length; i++) {
            const start = videoElement.buffered.start(i);
            const end = videoElement.buffered.end(i);

            if (timeSeconds >= start && timeSeconds <= end) {
                // Time is within buffered range, no need to clear
                console.log(`🎯 Seek within buffered range [${start.toFixed(2)}-${end.toFixed(2)}]`);
                return false;
            }
        }

        // Clear buffers
        await this.clearBuffers();
        return true;
    }

    async recoverFromSeekError() {
        console.log('🔄 Attempting seek recovery');

        const videoElement = document.getElementById('secure-video');
        const currentTime = videoElement.currentTime;

        // Reset everything
        this.stopChunkLoader();
        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.loadedChunks.clear();
        this.failedChunks.clear();

        // Clear buffers
        await this.clearBuffers();

        // Re-initialize
        await this.appendInitSegments();

        // Restart
        this.startChunkLoader();

        console.log('✅ Seek recovery completed');
    }

    // ================================================
    // SEEKING & PLAYBACK CONTROL
    // ================================================

    async seek(timeSeconds) {
        if (!isFinite(timeSeconds) || this.isSeeking) {
            return;
        }

        console.log(`⏭️ Seeking to ${timeSeconds.toFixed(2)}s`);
        this.isSeeking = true;

        const videoElement = document.getElementById('secure-video');
        const chunkDuration = this.videoInfo.chunk_size_seconds;
        const targetChunk = Math.floor(timeSeconds / chunkDuration);

        try {
            // Stop current loading
            this.stopChunkLoader();

            // Clear pending operations
            if (this.fetchController) {
                this.fetchController.abort();
                this.fetchController = new AbortController();
            }

            // Clear all chunk tracking
            this.chunkQueue = [];
            this.pendingChunks.clear();
            this.loadedChunks.clear();
            this.failedChunks.clear();

            // Reset chunk failure tracking
            this.chunkFailures = {};

            // Only clear buffers if needed (more efficient)
            const bufferCleared = await this.clearBuffersIfNeeded(timeSeconds);

            if (bufferCleared) {
                // Re-fetch init segments
                await this.appendInitSegments();
            }

            // Set video time
            videoElement.currentTime = timeSeconds;

            // Restart loader with small delay
            setTimeout(() => {
                this.startChunkLoader();
            }, 100);

            // Wait for buffer (with timeout)
            await this.waitForBuffer(timeSeconds, 2.0);

        } catch (error) {
            console.error('Seek failed:', error);

            // Recovery attempt
            try {
                await this.recoverFromSeekError();
            } catch (recoveryError) {
                console.error('Seek recovery failed:', recoveryError);
            }

        } finally {
            this.isSeeking = false;
        }
    }

    async restart(videoElement) {
        console.log('🔄 Restarting playback');

        this.stopChunkLoader();

        if (this.fetchController) {
            this.fetchController.abort();
        }

        // Clear everything
        this.loadedChunks.clear();
        this.failedChunks.clear();
        this.pendingChunks.clear();
        this.chunkQueue = [];
        this.streamEnded = false;

        await this.clearBuffers();
        await this.appendInitSegments();

        videoElement.currentTime = 0;
        this.startChunkLoader();

        console.log('✅ Playback restarted');
    }

    // ================================================
    // HELPER METHODS
    // ================================================

    detectMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }

    selectBestQuality() {
        const screenHeight = window.screen.height;
        const qualities = ['1080p', '720p', '480p', '360p', '240p', '144p'];

        for (const quality of qualities) {
            if (this.availableQualities.includes(quality)) {
                const preset = this.getQualityPreset(quality);
                if (preset && screenHeight >= preset.height) {
                    return quality;
                }
            }
        }

        return this.availableQualities[0] || '360p';
    }

    getQualityPreset(quality) {
        const presets = {
            '144p': { height: 144, bandwidth: 120000 },
            '240p': { height: 240, bandwidth: 300000 },
            '360p': { height: 360, bandwidth: 800000 },
            '480p': { height: 480, bandwidth: 1400000 },
            '720p': { height: 720, bandwidth: 2800000 },
            '1080p': { height: 1080, bandwidth: 5000000 }
        };

        return presets[quality];
    }

    async waitForBufferUpdate(buffer) {
        return new Promise((resolve) => {
            if (!buffer.updating) {
                resolve();
            } else {
                const onUpdateEnd = () => {
                    buffer.removeEventListener('updateend', onUpdateEnd);
                    resolve();
                };
                buffer.addEventListener('updateend', onUpdateEnd);
            }
        });
    }

    async waitForBuffer(time, duration = 1.0) {
        const videoElement = document.getElementById('secure-video');
        const targetEnd = time + duration;

        return new Promise((resolve) => {
            const startTime = Date.now();
            const timeout = 10000;

            const check = () => {
                if (Date.now() - startTime > timeout) {
                    console.warn('Buffer wait timeout');
                    return resolve();
                }

                for (let i = 0; i < videoElement.buffered.length; i++) {
                    if (videoElement.buffered.start(i) <= time &&
                        videoElement.buffered.end(i) >= targetEnd) {
                        return resolve();
                    }
                }

                requestAnimationFrame(check);
            };

            check();
        });
    }

    async clearBuffers() {
        try {
            // Clear video buffer
            if (this.videoBuffer && this.videoBuffer.buffered.length) {
                const start = this.videoBuffer.buffered.start(0);
                const end = this.videoBuffer.buffered.end(this.videoBuffer.buffered.length - 1);
                if (end > start) {
                    this.videoBuffer.remove(start, end);
                    await this.waitForBufferUpdate(this.videoBuffer);
                }
            }

            // Clear audio buffer
            if (this.audioBuffer && this.audioBuffer.buffered.length) {
                const start = this.audioBuffer.buffered.start(0);
                const end = this.audioBuffer.buffered.end(this.audioBuffer.buffered.length - 1);
                if (end > start) {
                    this.audioBuffer.remove(start, end);
                    await this.waitForBufferUpdate(this.audioBuffer);
                }
            }

        } catch (error) {
            console.warn('Buffer clear warning:', error);
        }
    }

    async clearAudioBuffer() {
        try {
            if (this.audioBuffer && this.audioBuffer.buffered.length) {
                const start = this.audioBuffer.buffered.start(0);
                const end = this.audioBuffer.buffered.end(this.audioBuffer.buffered.length - 1);
                if (end > start) {
                    this.audioBuffer.remove(start, end);
                    await this.waitForBufferUpdate(this.audioBuffer);
                }
            }
        } catch (error) {
            console.warn('Audio buffer clear warning:', error);
        }
    }

    clearAudioChunkTracking() {
        // Clear audio-related chunks from tracking sets
        // This is simplified - in production you'd track audio chunks separately
        this.loadedChunks.clear();
        this.failedChunks.clear();
        this.pendingChunks.clear();
    }

    getChunkRetryCount(chunkIndex) {
        // Count how many times this chunk has failed
        let count = 0;
        // This would need to track per-chunk retry counts
        return count;
    }

    // ================================================
    // DRM & SECURITY
    // ================================================

    async getEphemeralKey(chunkIndex) {
        const response = await fetch(
            `../api/get_chunk_key.php?` +
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

        return keyData;
    }

    async decryptWithEphemeralKey(encryptedData, keyData) {
        const key = await window.crypto.subtle.importKey(
            'raw',
            this.base64ToArrayBuffer(keyData.key),
            { name: 'AES-CTR', length: 256 },
            false,
            ['decrypt']
        );

        const iv = this.base64ToArrayBuffer(keyData.iv);

        const decrypted = await window.crypto.subtle.decrypt(
            {
                name: 'AES-CTR',
                counter: iv,
                length: 64
            },
            key,
            encryptedData
        );

        return decrypted;
    }

    base64ToArrayBuffer(base64) {
        const binaryString = atob(base64);
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes;
    }

    // ================================================
    // HEARTBEAT & SESSION MANAGEMENT
    // ================================================

    startHeartbeat() {
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
        }

        this.heartbeatInterval = setInterval(async () => {
            try {
                const videoElement = document.getElementById('secure-video');
                const currentTime = videoElement ? videoElement.currentTime : 0;

                const response = await fetch('../api/playback_heartbeat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `token=${encodeURIComponent(this.sessionToken)}&current_time=${currentTime}`
                });

                if (!response.ok) {
                    console.warn('Heartbeat failed');
                }

            } catch (error) {
                console.error('Heartbeat error:', error);
            }
        }, this.config.heartbeatInterval);
    }

    // ================================================
    // CLEANUP & UTILITY
    // ================================================

    stopChunkLoader() {
        if (this.loaderInterval) {
            clearInterval(this.loaderInterval);
            this.loaderInterval = null;
        }

        if (this.bufferCleanupInterval) {
            clearInterval(this.bufferCleanupInterval);
            this.bufferCleanupInterval = null;
        }
    }

    endStream() {
        if (!this.streamEnded && this.mediaSource && this.mediaSource.readyState === 'open') {
            this.mediaSource.endOfStream();
            this.streamEnded = true;
            console.log('🏁 Stream ended');
        }
    }

    cleanup() {
        console.log('🧹 Cleaning up decryptor');

        // Stop all intervals
        this.stopChunkLoader();

        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }

        // Abort all fetches
        if (this.fetchController) {
            this.fetchController.abort();
            this.fetchController = null;
        }

        // Clear data
        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.loadedChunks.clear();
        this.failedChunks.clear();

        // End MediaSource
        if (this.mediaSource && this.mediaSource.readyState === 'open') {
            try {
                this.mediaSource.endOfStream();
            } catch (e) {
                // Ignore
            }
        }

        this.isInitialized = false;
        console.log('✅ Cleanup complete');
    }

    getStatus() {
        return {
            initialized: this.isInitialized,
            videoId: this.videoId,
            currentQuality: this.currentQuality,
            currentAudioTrack: this.currentAudioTrack,
            maxChunks: this.maxChunks,
            loadedChunks: this.loadedChunks.size,
            pendingChunks: this.pendingChunks.size,
            queueLength: this.chunkQueue.length,
            streamEnded: this.streamEnded,
            isSeeking: this.isSeeking,
            qualitySwitching: this.qualitySwitching,
            audioSwitching: this.audioSwitching,
            stats: this.stats
        };
    }
}

// Export
if (typeof module !== 'undefined' && module.exports) {
    module.exports = EnhancedVideoDecryptor;
} else {
    window.VideoDecryptor = EnhancedVideoDecryptor;
}

// Debug helper
window.debugDecryptor = function () {
    if (window.videoDecryptor) {
        console.table(window.videoDecryptor.getStatus());
    } else {
        console.log('VideoDecryptor not initialized');
    }
};

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.videoDecryptor) {
        window.videoDecryptor.cleanup();
    }
});

console.log('🔐 Enhanced VideoDecryptor loaded');