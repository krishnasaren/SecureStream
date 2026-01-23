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
            
            // Step 2: Fetch video info
            const videoInfo = await this.fetchVideoInfo();
            if (!videoInfo || !videoInfo.chunk_count) {
                throw new Error('Failed to get video information');
            }
            
            this.videoInfo = videoInfo;
            this.chunkCount = videoInfo.chunk_count;
            console.log(`📊 Video has ${this.chunkCount} chunks`);
            
            // Step 3: Start heartbeat to keep session alive
            this.startHeartbeat();
            
            this.isInitialized = true;
            console.log('✅ Secure decryptor initialized');
            
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
        const url = `/stream/api/stream_chunk_secure.php?` +
                    `video_id=${encodeURIComponent(this.videoId)}` +
                    `&track=${track}` +
                    `&index=${index}` +
                    `&session_token=${encodeURIComponent(this.sessionToken)}`;

        try {
            const response = await fetch(url, {
                signal: this.abortSignal,
                headers: { 'Cache-Control': 'no-cache' }
            });

            if (!response.ok) {
                if (response.status === 404) {
                    console.warn(`Chunk ${track}:${index} not found`);
                    return null;
                }
                throw new Error(`HTTP ${response.status}`);
            }

            const encryptedData = await response.arrayBuffer();
            
            // Remove watermark (first 8 bytes)
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