/**
 * Enhanced VideoDecryptor Class
 * High-performance streaming video decryption with MediaSource API
 * Features: Advanced buffering, seek optimization, error recovery
 */

class VideoDecryptor {
    constructor() {
        // Core properties
        this.videoId = null;
        this.videoKey = null;
        this.videoIV = null;
        this.videoInfo = null;
        this.chunkCount = 0;
        this.currentChunk = 0;
        
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
        this.chunkCache = new Map();
        this.pendingChunks = new Set();
        
        // Performance optimization
        this.fetchController = null;
        this.abortSignal = null;
        this.chunkLoaderInterval = null;
        this.lastBufferCleanup = 0;
        
        // Configuration
        this.config = {
            encryptionMethod: 'AES-CTR',
            maxQueueSize: 3,
            bufferAhead: 25, // seconds
            bufferBehind: 15, // seconds
            maxBuffer: 40, // seconds
            cleanupInterval: 10000, // ms
            retryAttempts: 3,
            retryDelay: 1000 // ms
        };

        console.log('🎬 VideoDecryptor created');
    }

    // ================================================
    // INITIALIZATION
    // ================================================

    async init(videoId) {
        try {
            this.videoId = videoId;
            console.log('📡 Initializing VideoDecryptor for:', videoId);
            
            // Fetch video info
            const videoInfo = await this.fetchVideoInfo();
            if (!videoInfo || !videoInfo.chunk_count) {
                throw new Error('Failed to get video information');
            }
            
            this.videoInfo = videoInfo;
            this.chunkCount = videoInfo.chunk_count;
            console.log(`📊 Video has ${this.chunkCount} chunks, ${videoInfo.chunk_size_seconds}s each`);
            
            // Fetch and import encryption key
            const keyData = await this.fetchVideoKey();
            this.videoKey = await this.importKey(keyData.key);
            this.videoIV = this.base64ToArrayBuffer(keyData.iv);
            
            this.isInitialized = true;
            console.log('✅ VideoDecryptor initialized successfully');
            
            return true;
        } catch (error) {
            console.error('❌ Failed to initialize VideoDecryptor:', error);
            this.isInitialized = false;
            throw error;
        }
    }

    async fetchVideoInfo() {
        try {
            const response = await fetch(`/stream/api/video_info.php?id=${this.videoId}`, {
                credentials: 'same-origin',
                headers: { 'Cache-Control': 'no-cache' }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            return await response.json();
        } catch (error) {
            console.error('Failed to fetch video info:', error);
            throw error;
        }
    }

    async fetchVideoKey() {
        console.log('🔑 Fetching encryption key');
        
        try {
            const response = await fetch(`/stream/api/get_video_key.php?id=${this.videoId}`, {
                headers: {
                    'Cache-Control': 'no-cache',
                    'Pragma': 'no-cache'
                }
            });

            if (!response.ok) {
                throw new Error(`Server error: ${response.status}`);
            }

            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Failed to get video key');
            }

            console.log('✅ Encryption key received');
            return {
                key: data.key,
                iv: data.iv,
                method: data.algorithm
            };
        } catch (error) {
            console.error('Failed to fetch video key:', error);
            throw new Error('Could not retrieve decryption key');
        }
    }

    async importKey(base64Key) {
        try {
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
            
            console.log('🔐 Encryption key imported');
            return importedKey;
        } catch (error) {
            console.error('Failed to import encryption key:', error);
            throw new Error('Invalid encryption key format');
        }
    }

    // ================================================
    // MEDIASOURCE SETUP
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
                    
                    console.log('✅ MediaSource ready, streaming started');
                    resolve();
                } catch (err) {
                    console.error('MediaSource setup failed:', err);
                    reject(err);
                }
            }, { once: true });

            this.mediaSource.addEventListener('sourceended', () => {
                console.log('📺 MediaSource ended');
            });

            this.mediaSource.addEventListener('sourceclose', () => {
                console.log('🔌 MediaSource closed');
            });
        });
    }

    async initSourceBuffers() {
        try {
            // Video buffer
            const videoCodec = 'video/mp4; codecs="avc1.64001e"';
            if (!MediaSource.isTypeSupported(videoCodec)) {
                throw new Error('Video codec not supported');
            }
            this.videoBuffer = this.mediaSource.addSourceBuffer(videoCodec);

            // Audio buffer
            const audioCodec = 'audio/mp4; codecs="mp4a.40.2"';
            if (!MediaSource.isTypeSupported(audioCodec)) {
                throw new Error('Audio codec not supported');
            }
            this.audioBuffer = this.mediaSource.addSourceBuffer(audioCodec);

            console.log('📼 Video & Audio SourceBuffers created');

            // Event handlers for continuous processing
            this.videoBuffer.addEventListener('updateend', () => this.onBufferUpdateEnd());
            this.audioBuffer.addEventListener('updateend', () => this.onBufferUpdateEnd());

        } catch (error) {
            console.error('Failed to init SourceBuffers:', error);
            throw error;
        }
    }

    async appendInitSegments() {
        console.log('🎬 Fetching init segments...');

        try {
            const [vInit, aInit] = await Promise.all([
                this.fetchInitSegment('video'),
                this.fetchInitSegment('audio')
            ]);

            // Append video init
            this.videoBuffer.appendBuffer(vInit);
            await this.waitForUpdate(this.videoBuffer);

            // Append audio init
            this.audioBuffer.appendBuffer(aInit);
            await this.waitForUpdate(this.audioBuffer);

            // Set duration
            const totalDuration = this.videoInfo.chunk_count * this.videoInfo.chunk_size_seconds;
            this.mediaSource.duration = totalDuration;

            console.log(`✅ Init segments appended, duration: ${totalDuration}s`);

        } catch (error) {
            console.error('Failed to append init segments:', error);
            throw error;
        }
    }

    async fetchInitSegment(track) {
        const url = `/stream/api/init_segment.php?video_id=${this.videoId}&track=${track}`;
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`Failed to fetch ${track} init segment`);
        }
        
        return response.arrayBuffer();
    }

    // ================================================
    // CHUNK LOADING & MANAGEMENT
    // ================================================

    startChunkLoader() {
        console.log('⚙️ Starting chunk loader');

        // Clear any existing interval
        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }

        const videoEl = document.getElementById('secure-video');

        this.chunkLoaderInterval = setInterval(() => {
            // Check if we need more chunks
            const bufferedAhead = this.getBufferedAhead(videoEl);
            const needsMoreChunks = bufferedAhead < this.config.bufferAhead;
            const hasCapacity = this.chunkQueue.length < this.config.maxQueueSize;
            const hasMoreChunks = this.currentChunk < this.chunkCount;

            if ((this.seeking || needsMoreChunks) && hasCapacity && hasMoreChunks) {
                this.loadNextChunks();
            }

            // Periodic buffer cleanup
            if (Date.now() - this.lastBufferCleanup > this.config.cleanupInterval) {
                this.cleanupOldBuffers(videoEl);
                this.lastBufferCleanup = Date.now();
            }

        }, 300); // Check every 300ms for responsive buffering

        // Load initial chunks immediately
        this.loadNextChunks();
    }

    async loadNextChunks() {
        // Prevent duplicate loads
        if (this.pendingChunks.has(this.currentChunk)) {
            return;
        }

        const index = this.currentChunk;

        if (index >= this.chunkCount) {
            // All chunks loaded, finalize stream
            if (!this.streamEnded && 
                this.chunkQueue.length === 0 && 
                this.mediaSource && 
                this.mediaSource.readyState === 'open') {
                
                console.log('🏁 All chunks loaded, ending stream');
                clearInterval(this.chunkLoaderInterval);
                this.mediaSource.endOfStream();
                this.streamEnded = true;
            }
            return;
        }

        try {
            this.pendingChunks.add(index);

            // Fetch video and audio chunks in parallel
            const [vEnc, aEnc] = await Promise.all([
                this.fetchChunk('video', index),
                this.fetchChunk('audio', index)
            ]);

            if (!vEnc || !aEnc) {
                this.pendingChunks.delete(index);
                return;
            }

            // Add to queue
            this.chunkQueue.push({
                index,
                video: vEnc,
                audio: aEnc
            });

            this.currentChunk++;
            this.pendingChunks.delete(index);

            // Start processing if not already running
            if (!this.isDecrypting) {
                this.processNextChunk();
            }

        } catch (error) {
            console.error(`Failed to load chunk ${index}:`, error);
            this.pendingChunks.delete(index);
            
            // Retry logic
            await this.retryChunkLoad(index);
        }
    }

    async retryChunkLoad(index) {
        for (let attempt = 1; attempt <= this.config.retryAttempts; attempt++) {
            console.log(`🔄 Retry ${attempt}/${this.config.retryAttempts} for chunk ${index}`);
            
            await new Promise(resolve => setTimeout(resolve, this.config.retryDelay * attempt));
            
            try {
                const [vEnc, aEnc] = await Promise.all([
                    this.fetchChunk('video', index),
                    this.fetchChunk('audio', index)
                ]);

                if (vEnc && aEnc) {
                    this.chunkQueue.push({ index, video: vEnc, audio: aEnc });
                    
                    if (!this.isDecrypting) {
                        this.processNextChunk();
                    }
                    
                    console.log(`✅ Chunk ${index} loaded on retry ${attempt}`);
                    return;
                }
            } catch (error) {
                console.error(`Retry ${attempt} failed for chunk ${index}:`, error);
            }
        }

        console.error(`❌ All retries failed for chunk ${index}`);
    }

    async fetchChunk(track, index) {
        const url = `/stream/api/stream_chunk.php?video_id=${this.videoId}&track=${track}&index=${index}`;

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

            return await response.arrayBuffer();

        } catch (error) {
            if (error.name === 'AbortError') {
                console.log(`Fetch aborted for ${track}:${index}`);
                return null;
            }
            throw error;
        }
    }

    // ================================================
    // CHUNK PROCESSING & DECRYPTION
    // ================================================

    async processNextChunk() {
        // Safety checks
        if (this.isDecrypting ||
            this.chunkQueue.length === 0 ||
            !this.mediaSource ||
            this.mediaSource.readyState !== 'open') {
            return;
        }

        // Don't append if buffers are updating
        if (this.videoBuffer.updating || this.audioBuffer.updating) {
            return;
        }

        // Check buffer capacity
        const videoEl = document.getElementById('secure-video');
        const bufferedAhead = this.getBufferedAhead(videoEl);

        if (!this.seeking && 
            !videoEl.paused && 
            bufferedAhead > this.config.maxBuffer) {
            return; // Buffer is full enough
        }

        this.isDecrypting = true;

        try {
            const chunk = this.chunkQueue.shift();

            // Decrypt video and audio in parallel
            const [vDec, aDec] = await Promise.all([
                this.decryptChunk(chunk.video, chunk.index),
                this.decryptChunk(chunk.audio, chunk.index)
            ]);

            // Cleanup old buffers before appending
            await this.cleanupOldBuffers(videoEl);

            // Append video first
            this.videoBuffer.appendBuffer(vDec);
            await this.waitForUpdate(this.videoBuffer);

            // Then append audio
            this.audioBuffer.appendBuffer(aDec);
            await this.waitForUpdate(this.audioBuffer);

            console.log(`✅ Chunk ${chunk.index} appended successfully`);

        } catch (error) {
            console.error('Failed to process chunk:', error);
        } finally {
            this.isDecrypting = false;

            // Continue processing queue
            if (this.chunkQueue.length > 0) {
                // Use microtask to avoid blocking
                queueMicrotask(() => this.processNextChunk());
            }
        }
    }

    async decryptChunk(encryptedData, chunkIndex) {
        if (!encryptedData || encryptedData.byteLength === 0) {
            throw new Error(`Empty chunk data at index ${chunkIndex}`);
        }
        
        try {
            const chunkIV = this.generateChunkIV(chunkIndex);
            
            const decrypted = await window.crypto.subtle.decrypt(
                {
                    name: this.config.encryptionMethod,
                    counter: chunkIV,
                    length: 64
                },
                this.videoKey,
                encryptedData
            );

            return decrypted;
            
        } catch (error) {
            console.error(`Failed to decrypt chunk ${chunkIndex}:`, error);
            throw error;
        }
    }

    generateChunkIV(chunkIndex) {
        // Create unique IV for each chunk
        const chunkIV = new Uint8Array(this.videoIV);
        
        // XOR the last 4 bytes with chunk index (big-endian)
        const indexBytes = new Uint8Array(4);
        new DataView(indexBytes.buffer).setUint32(0, chunkIndex, false);
        
        for (let i = 0; i < 4; i++) {
            chunkIV[12 + i] = chunkIV[12 + i] ^ indexBytes[i];
        }
        
        return chunkIV;
    }

    onBufferUpdateEnd() {
        // Continue processing when buffer is ready
        if (!this.isDecrypting && this.chunkQueue.length > 0) {
            this.processNextChunk();
        }
    }

    // ================================================
    // SEEKING
    // ================================================

    async seek(timeSeconds) {
        const chunkDuration = this.videoInfo.chunk_size_seconds;
        const targetChunk = Math.floor(timeSeconds / chunkDuration);

        console.log(`⏭️ Seeking to ${timeSeconds.toFixed(2)}s (chunk ${targetChunk})`);

        this.seeking = true;

        // Stop loader
        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }

        // Abort pending fetches
        if (this.fetchController) {
            this.fetchController.abort();
            this.fetchController = new AbortController();
            this.abortSignal = this.fetchController.signal;
        }

        // Reset state
        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.currentChunk = targetChunk;
        this.isDecrypting = false;
        this.streamEnded = false;

        // Clear buffers
        await this.clearAllBuffers();

        // Set video time immediately for responsive UI
        const video = document.getElementById('secure-video');
        video.currentTime = timeSeconds;

        // Restart loader and force immediate load
        this.startChunkLoader();

        // Wait for buffer to have data at seek position
        await this.waitForBuffer(timeSeconds, 2.0); // Wait for 2s of data

        this.seeking = false;

        console.log('✅ Seek completed');
    }

    async waitForBuffer(time, duration = 1.0) {
        const video = document.getElementById('secure-video');
        const targetEnd = time + duration;

        return new Promise((resolve) => {
            const startTime = Date.now();
            const timeout = 10000; // 10s timeout

            const check = () => {
                // Timeout check
                if (Date.now() - startTime > timeout) {
                    console.warn('Buffer wait timeout');
                    return resolve();
                }

                // Check if we have enough buffered data
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

    // ================================================
    // BUFFER MANAGEMENT
    // ================================================

    async cleanupOldBuffers(video) {
        if (!video || video.currentTime === 0) return;

        const removeEnd = video.currentTime - this.config.bufferBehind;
        if (removeEnd <= 0) return;

        try {
            // Clean video buffer
            if (this.videoBuffer.buffered.length && !this.videoBuffer.updating) {
                for (let i = 0; i < this.videoBuffer.buffered.length; i++) {
                    const start = this.videoBuffer.buffered.start(i);
                    const end = this.videoBuffer.buffered.end(i);
                    
                    if (end < removeEnd) {
                        // Entire range is old, remove it
                        this.videoBuffer.remove(start, end);
                        await this.waitForUpdate(this.videoBuffer);
                    } else if (start < removeEnd) {
                        // Partial range is old
                        this.videoBuffer.remove(start, Math.min(removeEnd, end));
                        await this.waitForUpdate(this.videoBuffer);
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

        console.log('🧹 Clearing all buffers');

        try {
            // Clear video buffer
            if (this.videoBuffer.buffered.length && !this.videoBuffer.updating) {
                const end = this.videoBuffer.buffered.end(this.videoBuffer.buffered.length - 1);
                this.videoBuffer.remove(0, end);
                await this.waitForUpdate(this.videoBuffer);
            }

            // Clear audio buffer
            if (this.audioBuffer.buffered.length && !this.audioBuffer.updating) {
                const end = this.audioBuffer.buffered.end(this.audioBuffer.buffered.length - 1);
                this.audioBuffer.remove(0, end);
                await this.waitForUpdate(this.audioBuffer);
            }

            console.log('✅ Buffers cleared');

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

    // ================================================
    // RESTART
    // ================================================

    async restart(videoElement) {
        console.log('🔄 Full restart');

        // Stop everything
        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
        }

        if (this.fetchController) {
            this.fetchController.abort();
        }

        // Reset state
        this.chunkQueue = [];
        this.pendingChunks.clear();
        this.currentChunk = 0;
        this.isDecrypting = false;
        this.streamEnded = false;
        this.seeking = false;

        // Close old MediaSource
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

        // Create new MediaSource
        await this.prepareMediaSource(videoElement);

        console.log('✅ Restart complete');
    }

    // ================================================
    // UTILITY FUNCTIONS
    // ================================================

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

    // ================================================
    // CLEANUP
    // ================================================

    cleanup() {
        console.log('🧹 Cleaning up VideoDecryptor');
        
        // Stop loader
        if (this.chunkLoaderInterval) {
            clearInterval(this.chunkLoaderInterval);
            this.chunkLoaderInterval = null;
        }
        
        // Abort fetches
        if (this.fetchController) {
            this.fetchController.abort();
            this.fetchController = null;
        }
        
        // Clear data
        this.chunkCache.clear();
        this.chunkQueue = [];
        this.pendingChunks.clear();
        
        // Close MediaSource
        if (this.mediaSource) {
            try {
                if (this.mediaSource.readyState === 'open') {
                    this.mediaSource.endOfStream();
                }
            } catch (e) {
                console.warn('MediaSource cleanup warning:', e);
            }
            this.mediaSource = null;
        }
        
        // Reset state
        this.videoBuffer = null;
        this.audioBuffer = null;
        this.isInitialized = false;
        this.streamEnded = false;
        this.currentChunk = 0;
        
        console.log('✅ VideoDecryptor cleaned up');
    }

    // ================================================
    // DEBUG HELPERS
    // ================================================

    getStatus() {
        return {
            initialized: this.isInitialized,
            videoId: this.videoId,
            currentChunk: this.currentChunk,
            totalChunks: this.chunkCount,
            queueSize: this.chunkQueue.length,
            pendingChunks: this.pendingChunks.size,
            streamEnded: this.streamEnded,
            seeking: this.seeking,
            isDecrypting: this.isDecrypting,
            mediaSourceState: this.mediaSource?.readyState,
            config: this.config
        };
    }
}

// ================================================
// EXPORT & GLOBAL REGISTRATION
// ================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = VideoDecryptor;
} else {
    window.VideoDecryptor = VideoDecryptor;
}

// Debug helper
window.debugDecryptor = function() {
    if (window.videoDecryptor) {
        console.table(window.videoDecryptor.getStatus());
    } else {
        console.log('VideoDecryptor not initialized');
    }
};

console.log('✅ VideoDecryptor class loaded');