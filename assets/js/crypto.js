/**
 * ============================================
 * ENHANCED SECURE VIDEO DECRYPTOR V2 - FIXED
 * ============================================
 *
 * Fixes:
 * - Quality-scoped chunk tracking
 * - Proper retry management
 * - Independent audio/video handling
 * - Correct MediaSource lifecycle
 * - Better error classification
 * - Atomic state transitions
 * - Multi-range buffer clearing
 * - Quality switching fixed
 * - Seek playback resume
 * - Sequential chunk loading after seek
 * - Full browser compatibility
 */

class EnhancedVideoDecryptor {
  constructor() {
    // Core properties
    this.videoId = null;
    this.videoInfo = null;
    this.sessionToken = null;

    // Quality management
    this.availableQualities = [];
    this.currentQuality = "360p";
    this.qualityChunkMap = {}; // Map of quality -> {video: count, audio: [...]}

    // Audio management
    this.audioTracks = [];
    this.currentAudioTrack = 0;

    // Subtitle management
    this.subtitleTracks = [];
    this.currentSubtitle = -1;

    // Chunk tracking - QUALITY SCOPED
    this.chunkState = {
      video: new Map(), // key: "quality:index" -> state
      audio: new Map(), // key: "quality:track:index" -> state
    };

    // Chunk queues - with quality metadata
    this.chunkQueue = [];
    this.processingQueue = false;

    // MediaSource components
    this.mediaSource = null;
    this.videoBuffer = null;
    this.audioBuffer = null;

    // State management
    this.isInitialized = false;
    this.isPlaying = false;
    this.isSeeking = false;
    this.qualitySwitching = false;
    this.audioSwitching = false;
    this.streamEnded = false;

    // Quality-specific state
    this.currentQualityState = {
      quality: null,
      audioTrack: null,
      maxVideoChunks: 0,
      maxAudioChunks: 0,
      lastVideoChunk: -1,
      lastAudioChunk: -1,
    };

    // Performance optimization
    this.fetchController = new AbortController();
    this.loaderInterval = null;
    this.heartbeatInterval = null;
    this.bufferCleanupInterval = null;

    // Configuration
    this.config = {
      maxBufferAhead: 30,
      maxBufferBehind: 10,
      chunkLoadAhead: 3,
      maxRetries: 3,
      retryDelay: 1000,
      heartbeatInterval: 30000,
      bufferCheckInterval: 500,
      mobileBufferMultiplier: 1.5,
    };

    // Statistics
    this.stats = {
      chunksLoaded: 0,
      chunksFailed: 0,
      bytesLoaded: 0,
      switchAttempts: 0,
      recoveryAttempts: 0,
    };

    this.heartBeatRetry = 0;

    // Mobile detection
    this.isMobile = this.detectMobile();

    console.log("🎬 EnhancedVideoDecryptor V2 initialized");
  }

  // ================================================
  // INITIALIZATION
  // ================================================

  async init(videoId) {
    try {
      this.videoId = videoId;
      console.log("🔐 Initializing for video:", videoId);

      // Create playback session
      const session = await this.createPlaybackSession(videoId);
      if (!session.success) {
        throw new Error("Failed to create playback session");
      }

      this.sessionToken = session.session_token;

      // Fetch video info
      const videoInfo = await this.fetchVideoInfo();
      if (!videoInfo) {
        throw new Error("Failed to get video information");
      }

      this.videoInfo = videoInfo;

      // Fetch available tracks and qualities
      const tracksInfo = await this.fetchAvailableTracks();
      this.availableQualities = tracksInfo.qualities || ["360p"];
      this.audioTracks = tracksInfo.audio_tracks || [];
      this.subtitleTracks = tracksInfo.subtitle_tracks || [];

      // Build quality chunk map
      this.qualityChunkMap = videoInfo.chunk_map || {};

      /*console.log(
        "🗺️ Raw chunk map:",
        JSON.stringify(this.qualityChunkMap, null, 2),
      );*/

      // Auto-select best quality
      this.currentQuality = this.selectBestQuality();

      // Initialize quality state
      await this.initializeQualityState(
        this.currentQuality,
        this.currentAudioTrack,
      );

      console.log("📊 Video Info:", {
        qualities: this.availableQualities,
        audioTracks: this.audioTracks.length,
        currentQuality: this.currentQuality,
        videoChunks: this.currentQualityState.maxVideoChunks,
        audioChunks: this.currentQualityState.maxAudioChunks,
      });

      // Start heartbeat
      this.startHeartbeat();

      this.isInitialized = true;
      console.log("✅ Enhanced decryptor initialized");

      return true;
    } catch (error) {
      console.error("❌ Initialization failed:", error);
      this.isInitialized = false;
      throw error;
    }
  }

  async initializeQualityState(quality, audioTrack) {
    const chunkMap = this.qualityChunkMap[quality];

    if (!chunkMap) {
      throw new Error(`No chunk map for quality: ${quality}`);
    }

    this.currentQualityState = {
      quality: quality,
      audioTrack: audioTrack,
      maxVideoChunks: chunkMap.video || 0,
      maxAudioChunks: 0,
      lastVideoChunk: -1,
      lastAudioChunk: -1,
    };

    // Find audio chunk count - handle BOTH old and new formats
    if (chunkMap.audio) {
      let audioChunkCount = 0;

      if (Array.isArray(chunkMap.audio)) {
        // New format: array of audio streams
        const audioInfo = chunkMap.audio.find(
          (a) => a.stream_id === audioTrack + 1,
        );
        if (audioInfo) {
          audioChunkCount = audioInfo.count || 0;
        } else if (chunkMap.audio.length > 0) {
          audioChunkCount = chunkMap.audio[0].count || 0;
        }
      } else if (typeof chunkMap.audio === "object") {
        // Old format: object with stream IDs as keys
        const streamId = audioTrack + 1;
        const audioData = chunkMap.audio[streamId];

        if (audioData) {
          // Check for OLD format with chunks array
          if (audioData.chunks && Array.isArray(audioData.chunks)) {
            audioChunkCount = audioData.chunks.length;
            console.log(
              `⚠️ OLD FORMAT detected for stream ${streamId}: ${audioChunkCount} chunks in array`,
            );
          } else if (audioData.count) {
            // New format with count
            audioChunkCount = audioData.count;
          }
        } else {
          // Fallback: use first available stream
          const firstKey = Object.keys(chunkMap.audio)[0];
          if (firstKey && chunkMap.audio[firstKey]) {
            const firstAudio = chunkMap.audio[firstKey];
            if (firstAudio.chunks && Array.isArray(firstAudio.chunks)) {
              audioChunkCount = firstAudio.chunks.length;
              console.log(`⚠️ OLD FORMAT fallback: ${audioChunkCount} chunks`);
            } else {
              audioChunkCount = firstAudio.count || 0;
            }
          }
        }
      }

      this.currentQualityState.maxAudioChunks = audioChunkCount;
    }

    console.log(`📊 Quality state initialized:`, this.currentQualityState);
    console.log(`   Video chunks: ${this.currentQualityState.maxVideoChunks}`);
    console.log(`   Audio chunks: ${this.currentQualityState.maxAudioChunks}`);
    console.log(`   Audio track: ${audioTrack}, Stream ID: ${audioTrack + 1}`);
  }

  async createPlaybackSession(videoId) {
    const response = await fetch("../api/init_playback.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `video_id=${encodeURIComponent(videoId)}&csrf_token=${encodeURIComponent(csrf_token)}`,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  }

  async fetchVideoInfo() {
    const response = await fetch("../api/video_info.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `id=${encodeURIComponent(this.videoId)}&csrf_token=${encodeURIComponent(csrf_token)}`,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  }

  async fetchAvailableTracks() {
    const response = await fetch("../api/get_qualities.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `video_id=${encodeURIComponent(this.videoId)}&csrf_token=${encodeURIComponent(csrf_token)}`,
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  }

  // ================================================
  // CHUNK STATE MANAGEMENT - QUALITY SCOPED
  // ================================================

  getChunkKey(type, quality, track, index) {
    if (type === "video") {
      return `${quality}:${index}`;
    } else {
      return `${quality}:${track}:${index}`;
    }
  }

  getChunkState(type, quality, track, index) {
    const key = this.getChunkKey(type, quality, track, index);
    return this.chunkState[type].get(key) || { status: "pending", retries: 0 };
  }

  setChunkState(type, quality, track, index, state) {
    const key = this.getChunkKey(type, quality, track, index);
    this.chunkState[type].set(key, state);
  }

  isChunkProcessed(type, quality, track, index) {
    const state = this.getChunkState(type, quality, track, index);
    return (
      state.status === "loaded" ||
      state.status === "skipped" ||
      state.status === "loading"
    );
  }

  clearQualityChunks(quality) {
    // Remove all chunks for a specific quality
    for (const [key] of this.chunkState.video) {
      if (key.startsWith(`${quality}:`)) {
        this.chunkState.video.delete(key);
      }
    }

    for (const [key] of this.chunkState.audio) {
      if (key.startsWith(`${quality}:`)) {
        this.chunkState.audio.delete(key);
      }
    }
  }

  // ================================================
  // MEDIASOURCE MANAGEMENT
  // ================================================

  async prepareMediaSource(videoElement) {
    if (!this.isInitialized) {
      throw new Error("Decryptor not initialized");
    }

    console.log("🎥 Preparing MediaSource");

    // Create new MediaSource
    this.mediaSource = new MediaSource();
    const mediaSourceURL = URL.createObjectURL(this.mediaSource);
    videoElement.src = mediaSourceURL;

    return new Promise((resolve, reject) => {
      this.mediaSource.addEventListener(
        "sourceopen",
        async () => {
          try {
            await this.initSourceBuffers();
            await this.appendInitSegments();
            this.startChunkLoader();
            this.startBufferMonitor();

            console.log("✅ MediaSource ready");
            resolve();
          } catch (error) {
            reject(error);
          }
        },
        { once: true },
      );
    });
  }

  async initSourceBuffers() {
    const videoCodec = 'video/mp4; codecs="avc1.64001e"';
    const audioCodec = 'audio/mp4; codecs="mp4a.40.2"';

    if (!MediaSource.isTypeSupported(videoCodec)) {
      throw new Error("Video codec not supported");
    }
    if (!MediaSource.isTypeSupported(audioCodec)) {
      throw new Error("Audio codec not supported");
    }

    // Remove existing buffers if any (Firefox compatibility)
    if (this.videoBuffer) {
      try {
        if (!this.isFirefox()) {
          this.mediaSource.removeSourceBuffer(this.videoBuffer);
        }
      } catch (e) {
        console.warn("Could not remove video buffer:", e);
      }
      this.videoBuffer = null;
    }

    if (this.audioBuffer) {
      try {
        if (!this.isFirefox()) {
          this.mediaSource.removeSourceBuffer(this.audioBuffer);
        }
      } catch (e) {
        console.warn("Could not remove audio buffer:", e);
      }
      this.audioBuffer = null;
    }

    // Create new buffers
    this.videoBuffer = this.mediaSource.addSourceBuffer(videoCodec);
    this.audioBuffer = this.mediaSource.addSourceBuffer(audioCodec);

    // Add event listeners
    this.videoBuffer.addEventListener("updateend", () =>
      this.onBufferUpdateEnd("video"),
    );
    this.videoBuffer.addEventListener("error", (e) =>
      console.error("Video buffer error:", e),
    );

    this.audioBuffer.addEventListener("updateend", () =>
      this.onBufferUpdateEnd("audio"),
    );
    this.audioBuffer.addEventListener("error", (e) =>
      console.error("Audio buffer error:", e),
    );

    console.log("📼 SourceBuffers created");
  }

  async appendInitSegments() {
    console.log("🎬 Fetching init segments...");

    try {
      // CRITICAL: Reset timestampOffset BEFORE appending init segments
      if (this.videoBuffer && !this.videoBuffer.updating) {
        this.videoBuffer.timestampOffset = 0;
      }
      if (this.audioBuffer && !this.audioBuffer.updating) {
        this.audioBuffer.timestampOffset = 0;
      }

      const [vInit, aInit] = await Promise.all([
        this.fetchInitSegment("video", this.currentQuality),
        this.fetchInitSegment(
          "audio",
          this.currentQuality,
          this.currentAudioTrack,
        ),
      ]);

      // Append video init
      this.videoBuffer.appendBuffer(vInit);
      await this.waitForBufferUpdate(this.videoBuffer);

      // Append audio init
      this.audioBuffer.appendBuffer(aInit);
      await this.waitForBufferUpdate(this.audioBuffer);

      // Set MediaSource duration
      const maxChunks = Math.max(
        this.currentQualityState.maxVideoChunks,
        this.currentQualityState.maxAudioChunks,
      );
      const totalDuration = maxChunks * this.videoInfo.chunk_size_seconds;

      if (this.mediaSource.readyState === "open") {
        this.mediaSource.duration = totalDuration;
      }

      console.log(`✅ Init segments appended, duration: ${totalDuration}s`);
    } catch (error) {
      console.error("Failed to append init segments:", error);
      throw error;
    }
  }

  async fetchInitSegment(track, quality, audioTrack = null) {
    let url =
      `../api/init_segment.php?` +
      `video_id=${encodeURIComponent(this.videoId)}` +
      `&track=${track}` +
      `&quality=${encodeURIComponent(quality)}` +
      `&csrf_token=${encodeURIComponent(csrf_token)}`;

    if (track === "audio" && audioTrack !== null) {
      url += `&audio_track=${audioTrack}`;
    }

    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`Failed to fetch ${track} init segment`);
    }

    return response.arrayBuffer();
  }

  // ================================================
  // CHUNK LOADING & STREAMING - FIXED
  // ================================================

  startChunkLoader() {
    if (this.loaderInterval) {
      clearInterval(this.loaderInterval);
    }

    const loadInterval = this.isMobile ? 300 : 200;

    this.loaderInterval = setInterval(() => {
      this.manageChunkLoading();
    }, loadInterval);

    console.log("⚙️ Chunk loader started");
  }

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

  async manageChunkLoading() {
    const videoElement = document.getElementById("secure-video");
    if (!videoElement) return;

    // Skip if transitioning
    if (this.isSeeking || this.qualitySwitching || this.audioSwitching) {
      return;
    }

    // Skip if buffers updating
    if (this.videoBuffer.updating || this.audioBuffer.updating) {
      return;
    }

    const currentTime = videoElement.currentTime;
    const chunkDuration = this.videoInfo.chunk_size_seconds;
    const currentChunk = Math.floor(currentTime / chunkDuration);

    // Calculate buffer ahead
    const bufferedAhead = this.getBufferedAhead(videoElement);
    const chunksAhead = Math.floor(bufferedAhead / chunkDuration);

    const targetChunksAhead = this.isMobile
      ? this.config.chunkLoadAhead * this.config.mobileBufferMultiplier
      : this.config.chunkLoadAhead;

    // FIXED: Sequential chunk loading
    if (chunksAhead < targetChunksAhead) {
      const chunksToLoad = Math.ceil(targetChunksAhead - chunksAhead);
      const maxVideoChunks = this.currentQualityState.maxVideoChunks;
      const maxAudioChunks = this.currentQualityState.maxAudioChunks;

      let chunksLoaded = 0;
      let checkIndex = currentChunk;

      while (chunksLoaded < chunksToLoad) {
        // Check bounds - stop only if BOTH streams exhausted
        const videoExhausted = checkIndex >= maxVideoChunks;
        const audioExhausted = checkIndex >= maxAudioChunks;

        if (videoExhausted && audioExhausted) {
          if (!this.streamEnded) {
            this.endStream();
          }
          break;
        }

        // Check if chunk already loaded
        const videoLoaded = this.isChunkProcessed(
          "video",
          this.currentQuality,
          0,
          checkIndex,
        );
        const audioLoaded = this.isChunkProcessed(
          "audio",
          this.currentQuality,
          this.currentAudioTrack,
          checkIndex,
        );

        // Only load if not already loaded
        if (!videoLoaded || !audioLoaded) {
          await this.loadChunkPair(checkIndex);
          chunksLoaded++;
        }

        checkIndex++;

        // Safety limit to prevent infinite loops
        if (checkIndex > currentChunk + targetChunksAhead + 10) {
          break;
        }
      }
    }

    // Cleanup old buffers
    this.cleanupOldBuffers(videoElement);
  }

  async loadChunkPair(chunkIndex) {
    const quality = this.currentQuality;
    const audioTrack = this.currentAudioTrack;

    // Check if already processed
    const videoProcessed = this.isChunkProcessed(
      "video",
      quality,
      0,
      chunkIndex,
    );
    const audioProcessed = this.isChunkProcessed(
      "audio",
      quality,
      audioTrack,
      chunkIndex,
    );

    if (videoProcessed && audioProcessed) {
      return;
    }

    // Load independently
    const videoPromise = this.loadSingleChunk("video", quality, 0, chunkIndex);
    const audioPromise = this.loadSingleChunk(
      "audio",
      quality,
      audioTrack,
      chunkIndex,
    );

    // Wait for both, but handle independently
    const [videoResult, audioResult] = await Promise.allSettled([
      videoPromise,
      audioPromise,
    ]);

    // Check results
    const videoData =
      videoResult.status === "fulfilled" ? videoResult.value : null;
    const audioData =
      audioResult.status === "fulfilled" ? audioResult.value : null;

    // Queue if we have at least video (audio is optional for some streams)
    if (videoData) {
      this.chunkQueue.push({
        index: chunkIndex,
        quality: quality,
        audioTrack: audioTrack,
        video: videoData,
        audio: audioData, // Can be null if no audio chunks exist
      });

      this.stats.chunksLoaded++;

      // Process queue if not busy
      if (
        !this.processingQueue &&
        !this.videoBuffer.updating &&
        !this.audioBuffer.updating
      ) {
        this.processChunkQueue();
      }
    } else {
      console.warn(`⚠️ No video data for chunk ${chunkIndex}, skipping`);
    }
  }

  async loadSingleChunk(type, quality, track, index) {
    // Check if should skip
    const state = this.getChunkState(type, quality, track, index);

    if (state.retries >= this.config.maxRetries) {
      console.log(`⏭️ Skipping ${type} chunk ${index} (max retries)`);
      this.setChunkState(type, quality, track, index, {
        status: "skipped",
        retries: state.retries,
      });
      return null;
    }

    // Check bounds
    const maxChunks =
      type === "video"
        ? this.currentQualityState.maxVideoChunks
        : this.currentQualityState.maxAudioChunks;

    // If no audio chunks exist at all, skip audio loading
    if (type === "audio" && maxChunks === 0) {
      this.setChunkState(type, quality, track, index, {
        status: "skipped",
        retries: 0,
      });
      return null;
    }

    if (index >= maxChunks) {
      this.setChunkState(type, quality, track, index, {
        status: "skipped",
        retries: 0,
      });
      return null;
    }

    // Mark as loading
    this.setChunkState(type, quality, track, index, {
      status: "loading",
      retries: state.retries,
    });

    try {
      const data = await this.fetchAndDecryptChunk(type, quality, track, index);

      // Mark as loaded
      this.setChunkState(type, quality, track, index, {
        status: "loaded",
        retries: 0,
      });

      return data;
    } catch (error) {
      console.warn(`Failed to load ${type} chunk ${index}:`, error.message);

      // Classify error
      const errorType = this.classifyError(error);

      if (errorType === "NOT_FOUND") {
        // Chunk doesn't exist - skip permanently
        this.setChunkState(type, quality, track, index, {
          status: "skipped",
          retries: state.retries,
        });
        return null;
      }

      // Retry
      if (state.retries < this.config.maxRetries) {
        const delay = this.config.retryDelay * Math.pow(2, state.retries);
        console.log(
          `🔁 Retrying ${type} chunk ${index} in ${delay}ms (attempt ${state.retries + 1})`,
        );

        await new Promise((resolve) => setTimeout(resolve, delay));

        this.setChunkState(type, quality, track, index, {
          status: "pending",
          retries: state.retries + 1,
        });

        return this.loadSingleChunk(type, quality, track, index);
      }

      // Max retries reached
      this.setChunkState(type, quality, track, index, {
        status: "skipped",
        retries: state.retries,
      });
      this.stats.chunksFailed++;
      return null;
    }
  }

  async fetchAndDecryptChunk(type, quality, track, index) {
    const url =
      `../api/stream_chunk_secure.php?` +
      `video_id=${encodeURIComponent(this.videoId)}` +
      `&track=${type}` +
      `&index=${index}` +
      `&quality=${encodeURIComponent(quality)}` +
      `&audio_track=${track}` +
      `&session_token=${encodeURIComponent(this.sessionToken)}`;

    const response = await fetch(url, {
      signal: this.fetchController.signal,
      headers: {
        "Cache-Control": "no-cache",
        Pragma: "no-cache",
      },
    });

    if (!response.ok) {
      if (response.status === 404) {
        const error = new Error("Chunk not found");
        error.code = "NOT_FOUND";
        throw error;
      }
      throw new Error(`HTTP ${response.status}`);
    }

    const encryptedData = await response.arrayBuffer();

    if (encryptedData.byteLength === 0) {
      const error = new Error("Empty chunk");
      error.code = "NOT_FOUND";
      throw error;
    }

    // Remove watermark
    const actualData = encryptedData.slice(8);

    // Get ephemeral key and decrypt
    const keyData = await this.getEphemeralKey(index);
    const decryptedData = await this.decryptWithEphemeralKey(
      actualData,
      keyData,
    );

    return decryptedData;
  }

  classifyError(error) {
    if (
      error.code === "NOT_FOUND" ||
      error.message.includes("404") ||
      error.message.includes("not found") ||
      error.message.includes("Empty chunk")
    ) {
      return "NOT_FOUND";
    }

    if (error.name === "AbortError") {
      return "ABORTED";
    }

    if (error.message.includes("network") || error.message.includes("fetch")) {
      return "NETWORK";
    }

    if (error.message.includes("decrypt")) {
      return "DECRYPT";
    }

    return "UNKNOWN";
  }

  async processChunkQueue() {
    if (this.processingQueue || this.chunkQueue.length === 0) {
      return;
    }

    if (this.videoBuffer.updating || this.audioBuffer.updating) {
      return;
    }
    if (
      this.isSeeking ||
      this.qualitySwitching ||
      this.audioSwitching ||
      this.streamEnded
    ) {
      return;
    }

    this.processingQueue = true;

    try {
      while (this.chunkQueue.length > 0) {
        const chunk = this.chunkQueue[0];

        // Validate quality matches current
        if (chunk.quality !== this.currentQuality) {
          console.log(`⏭️ Skipping chunk from old quality: ${chunk.quality}`);
          this.chunkQueue.shift();
          continue;
        }

        if (chunk.audioTrack !== this.currentAudioTrack) {
          console.log(
            `⏭️ Skipping chunk from old audio track: ${chunk.audioTrack}`,
          );
          this.chunkQueue.shift();
          continue;
        }

        if (!this.canAppend(this.videoBuffer)) return;

        // Append video if present
        if (chunk.video && !this.videoBuffer.updating) {
          this.videoBuffer.appendBuffer(chunk.video);
          await this.waitForBufferUpdate(this.videoBuffer);
        }

        if (!this.canAppend(this.audioBuffer)) return;

        // Append audio if present
        if (chunk.audio && !this.audioBuffer.updating) {
          this.audioBuffer.appendBuffer(chunk.audio);
          await this.waitForBufferUpdate(this.audioBuffer);
        }

        console.log(`✅ Chunk ${chunk.index} appended (${chunk.quality})`);

        // Remove from queue
        this.chunkQueue.shift();

        // Check if buffers need update
        if (this.videoBuffer.updating || this.audioBuffer.updating) {
          break;
        }
      }
    } catch (error) {
      console.error("Queue processing error:", error);

      // Remove problematic chunk
      if (this.chunkQueue.length > 0) {
        const badChunk = this.chunkQueue.shift();
        console.warn(`🗑️ Removed problematic chunk ${badChunk.index}`);
      }
    } finally {
      this.processingQueue = false;
    }
  }

  onBufferUpdateEnd(bufferType) {
    setTimeout(() => {
      if (
        !this.processingQueue &&
        this.chunkQueue.length > 0 &&
        !this.videoBuffer.updating &&
        !this.audioBuffer.updating
      ) {
        this.processChunkQueue();
      }
    }, 50);
  }

  canAppend(buffer) {
    return (
      buffer &&
      this.mediaSource &&
      this.mediaSource.readyState === "open" &&
      !buffer.updating
    );
  }

  // ================================================
  // QUALITY SWITCHING - FIXED
  // ================================================

  async switchQuality(newQuality) {
    if (
      !this.availableQualities.includes(newQuality) ||
      newQuality === this.currentQuality ||
      this.qualitySwitching
    ) {
      return false;
    }

    console.log(`🔄 Switching quality: ${this.currentQuality} → ${newQuality}`);
    this.qualitySwitching = true;
    this.stats.switchAttempts++;

    const videoElement = document.getElementById("secure-video");
    const currentTime = videoElement.currentTime;
    const wasPlaying = !videoElement.paused;
    const oldQuality = this.currentQuality;

    try {
      // Pause playback
      if (wasPlaying) {
        videoElement.pause();
      }

      // Stop loader
      this.stopChunkLoader();

      // Abort in-flight requests
      this.fetchController.abort();
      this.fetchController = new AbortController();

      // Clear old quality chunks from state
      this.clearQualityChunks(this.currentQuality);

      // Clear queue
      this.chunkQueue = [];
      this.processingQueue = false;

      // Update quality BEFORE clearing buffers
      this.currentQuality = newQuality;

      // Initialize new quality state
      await this.initializeQualityState(newQuality, this.currentAudioTrack);

      // Clear buffers completely
      await this.clearBuffers();

      // CRITICAL FIX: Recreate source buffers for quality switch
      //await this.initSourceBuffers();

      // Re-append init segments with new quality
      await this.appendInitSegments();

      // Set video time
      videoElement.currentTime = currentTime;

      // Restart loader
      this.startChunkLoader();

      // Resume playback
      if (wasPlaying) {
        // Wait a bit for chunks to load
        setTimeout(async () => {
          try {
            await videoElement.play();
            console.log(`✅ Quality switched to ${newQuality} and playing`);
          } catch (err) {
            console.warn("Failed to resume playback:", err);
          }
        }, 500);
      } else {
        console.log(`✅ Quality switched to ${newQuality}`);
      }

      return true;
    } catch (error) {
      console.error("Quality switch failed:", error);
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
    if (
      trackIndex < 0 ||
      trackIndex >= this.audioTracks.length ||
      trackIndex === this.currentAudioTrack ||
      this.audioSwitching
    ) {
      return false;
    }

    console.log(
      `🎵 Switching audio track: ${this.currentAudioTrack} → ${trackIndex}`,
    );
    this.audioSwitching = true;

    const videoElement = document.getElementById("secure-video");
    const currentTime = videoElement.currentTime;
    const wasPlaying = !videoElement.paused;
    const oldTrack = this.currentAudioTrack;

    try {
      if (wasPlaying) {
        videoElement.pause();
      }

      this.stopChunkLoader();

      // Clear audio chunks for current track
      const quality = this.currentQuality;
      for (const [key] of this.chunkState.audio) {
        if (key.startsWith(`${quality}:${this.currentAudioTrack}:`)) {
          this.chunkState.audio.delete(key);
        }
      }

      // Clear audio from queue
      this.chunkQueue = this.chunkQueue.filter(
        (c) => c.audioTrack !== this.currentAudioTrack,
      );

      // Update track
      this.currentAudioTrack = trackIndex;

      // Update quality state
      await this.initializeQualityState(this.currentQuality, trackIndex);

      // Clear audio buffer
      await this.clearAudioBuffer();

      // Re-fetch audio init
      const audioInit = await this.fetchInitSegment(
        "audio",
        this.currentQuality,
        trackIndex,
      );
      this.audioBuffer.appendBuffer(audioInit);
      await this.waitForBufferUpdate(this.audioBuffer);

      // Restart loader
      this.startChunkLoader();

      if (wasPlaying) {
        setTimeout(async () => {
          try {
            await videoElement.play();
          } catch (err) {
            console.warn("Failed to resume playback:", err);
          }
        }, 300);
      }

      console.log(`✅ Audio track switched to ${trackIndex}`);
      return true;
    } catch (error) {
      console.error("Audio track switch failed:", error);
      this.currentAudioTrack = oldTrack;
      return false;
    } finally {
      this.audioSwitching = false;
    }
  }

  // ================================================
  // BUFFER MANAGEMENT - FIXED
  // ================================================

  getBufferedAhead(videoElement) {
    if (!videoElement.buffered.length) return 0;

    const currentTime = videoElement.currentTime;
    const bufferedEnd = videoElement.buffered.end(
      videoElement.buffered.length - 1,
    );

    return Math.max(0, bufferedEnd - currentTime);
  }

  async cleanupOldBuffers(videoElement) {
    if (!videoElement || videoElement.currentTime === 0) return;

    const removeEnd = videoElement.currentTime - this.config.maxBufferBehind;
    if (removeEnd <= 0) return;

    try {
      if (this.videoBuffer.buffered.length && !this.videoBuffer.updating) {
        for (let i = 0; i < this.videoBuffer.buffered.length; i++) {
          const start = this.videoBuffer.buffered.start(i);
          const end = this.videoBuffer.buffered.end(i);

          if (end < removeEnd) {
            this.videoBuffer.remove(start, end);
            await this.waitForBufferUpdate(this.videoBuffer);
            await new Promise((r) => setTimeout(r, 0));
          }
        }
      }

      if (this.audioBuffer.buffered.length && !this.audioBuffer.updating) {
        for (let i = 0; i < this.audioBuffer.buffered.length; i++) {
          const start = this.audioBuffer.buffered.start(i);
          const end = this.audioBuffer.buffered.end(i);

          if (end < removeEnd) {
            this.audioBuffer.remove(start, end);
            await this.waitForBufferUpdate(this.audioBuffer);
            await new Promise((r) => setTimeout(r, 0));
          }
        }
      }
    } catch (error) {
      console.warn("Buffer cleanup warning:", error);
    }
  }

  startBufferMonitor() {
    if (this.bufferCleanupInterval) {
      clearInterval(this.bufferCleanupInterval);
    }

    this.bufferCleanupInterval = setInterval(() => {
      const videoElement = document.getElementById("secure-video");
      if (videoElement) {
        this.cleanupOldBuffers(videoElement);
      }
    }, this.config.bufferCheckInterval);
  }

  // FIXED: Clear ALL buffered ranges and reset timestampOffset
  async clearBuffers() {
    try {
      if (this.videoBuffer && this.videoBuffer.buffered.length > 0) {
        // Remove all buffered ranges
        for (let i = this.videoBuffer.buffered.length - 1; i >= 0; i--) {
          const start = this.videoBuffer.buffered.start(i);
          const end = this.videoBuffer.buffered.end(i);
          if (end > start) {
            console.log(
              `🗑️ Clearing video buffer range ${i}: ${start.toFixed(2)}s - ${end.toFixed(2)}s`,
            );
            this.videoBuffer.remove(start, end);
            await this.waitForBufferUpdate(this.videoBuffer);
          }
        }
      }

      if (this.audioBuffer && this.audioBuffer.buffered.length > 0) {
        // Remove all buffered ranges
        for (let i = this.audioBuffer.buffered.length - 1; i >= 0; i--) {
          const start = this.audioBuffer.buffered.start(i);
          const end = this.audioBuffer.buffered.end(i);
          if (end > start) {
            console.log(
              `🗑️ Clearing audio buffer range ${i}: ${start.toFixed(2)}s - ${end.toFixed(2)}s`,
            );
            this.audioBuffer.remove(start, end);
            await this.waitForBufferUpdate(this.audioBuffer);
          }
        }
      }

      // CRITICAL: Reset timestampOffset to 0 after clearing
      if (this.videoBuffer && !this.videoBuffer.updating) {
        this.videoBuffer.timestampOffset = 0;
        console.log("🔄 Video timestampOffset reset to 0");
      }

      if (this.audioBuffer && !this.audioBuffer.updating) {
        this.audioBuffer.timestampOffset = 0;
        console.log("🔄 Audio timestampOffset reset to 0");
      }
    } catch (error) {
      console.warn("Buffer clear warning:", error);
    }
  }

  // FIXED: Clear all audio buffer ranges and reset timestampOffset
  async clearAudioBuffer() {
    try {
      if (this.audioBuffer && this.audioBuffer.buffered.length > 0) {
        // Remove all buffered ranges
        for (let i = this.audioBuffer.buffered.length - 1; i >= 0; i--) {
          const start = this.audioBuffer.buffered.start(i);
          const end = this.audioBuffer.buffered.end(i);
          if (end > start) {
            console.log(
              `🗑️ Clearing audio buffer range ${i}: ${start.toFixed(2)}s - ${end.toFixed(2)}s`,
            );
            this.audioBuffer.remove(start, end);
            await this.waitForBufferUpdate(this.audioBuffer);
          }
        }
      }

      // CRITICAL: Reset timestampOffset to 0 after clearing
      if (this.audioBuffer && !this.audioBuffer.updating) {
        this.audioBuffer.timestampOffset = 0;
        console.log("🔄 Audio timestampOffset reset to 0");
      }
    } catch (error) {
      console.warn("Audio buffer clear warning:", error);
    }
  }

  // ================================================
  // SEEKING & PLAYBACK CONTROL - FIXED
  // ================================================

  async seek(timeSeconds) {
    if (!isFinite(timeSeconds) || this.isSeeking) {
      return;
    }

    console.log(`⏭️ Seeking to ${timeSeconds.toFixed(2)}s`);
    this.isSeeking = true;

    const videoElement = document.getElementById("secure-video");
    const chunkDuration = this.videoInfo.chunk_size_seconds;
    const wasPlaying = !videoElement.paused;
    let bufferEmpty = false;
    let seekMismatch = false;

    try {
      if (wasPlaying) {
        videoElement.pause();
      }

      this.stopChunkLoader();

      this.fetchController.abort();
      this.fetchController = new AbortController();

      // Clear chunk state for current quality
      this.clearQualityChunks(this.currentQuality);
      this.chunkQueue = [];
      this.processingQueue = false;

      // Clear buffers
      await this.clearBuffers();

      // Re-append init segments
      await this.appendInitSegments();

      // Set time
      videoElement.currentTime = timeSeconds;

      // Restart loader and resume playback
      const seekTimeoutInterval = setTimeout(async () => {
        console.log(
          "🔄 Seek timeout callback started, wasPlaying:",
          wasPlaying,
        );
        this.startChunkLoader();
        this.isSeeking = false;

        // Resume playback if it was playing before
        if (wasPlaying) {
          console.log("⏳ Waiting for initial buffer...");
          // Wait for chunks to load
          await new Promise((resolve) => setTimeout(resolve, 500));

          console.log("▶️ Attempting to resume playback...");

          // Debug buffer state
          const videoBuffer = this.mediaSource.sourceBuffers[0];
          const audioBuffer = this.mediaSource.sourceBuffers[1];

          console.log(
            "📊 Video currentTime:",
            videoElement.currentTime.toFixed(2),
          );
          console.log("📊 Video paused:", videoElement.paused);
          console.log("📊 Video readyState:", videoElement.readyState);


          if (videoBuffer && videoBuffer.buffered.length > 0) {
            console.log(
              "📊 Video buffer:",
              videoBuffer.buffered.start(0).toFixed(2),
              "-",
              videoBuffer.buffered
                .end(videoBuffer.buffered.length - 1)
                .toFixed(2),
            );
            if (
              videoElement.currentTime.toFixed(2) <
                videoBuffer.buffered.start(0).toFixed(2) ||
              videoElement.currentTime.toFixed(2) >
                videoBuffer.buffered
                  .end(videoBuffer.buffered.length - 1)
                  .toFixed(2)
            ){
              seekMismatch = true;
            }
          } else {
            console.log("📊 Video buffer: EMPTY");
            bufferEmpty = true;
          }

          if (audioBuffer && audioBuffer.buffered.length > 0) {
            console.log(
              "📊 Audio buffer:",
              audioBuffer.buffered.start(0).toFixed(2),
              "-",
              audioBuffer.buffered
                .end(audioBuffer.buffered.length - 1)
                .toFixed(2),
            );
            if (
              videoElement.currentTime.toFixed(2) <
                audioBuffer.buffered.start(0).toFixed(2) ||
              videoElement.currentTime.toFixed(2) >
                audioBuffer.buffered
                  .end(videoBuffer.buffered.length - 1)
                  .toFixed(2)
            ) {
              seekMismatch = true;
            }
          } else {
            console.log("📊 Audio buffer: EMPTY");
            bufferEmpty = true;
          }

          if(bufferEmpty || seekMismatch){
            console.log("Seek Mismatch or Buffer Empty Operations");
            clearInterval(seekTimeoutInterval);
            await this.attemptRecovery();
            return;
          }

          try {
            await videoElement.play();
            console.log("✅ Playback resumed after seek");

            // Double-check after play
            setTimeout(() => {
              console.log(
                "🔍 After play - paused:",
                videoElement.paused,
                "time:",
                videoElement.currentTime.toFixed(2),
              );
            }, 100);
          } catch (err) {
            console.error("❌ Resume play failed:", err);
          }
        } else {
          console.log("⏸️ Not resuming - video was paused before seek");
        }
      }, 100);
    } catch (error) {
      console.error("Seek failed:", error);
      this.isSeeking = false;
      await this.attemptRecovery();
    }
  }

  async loadSubtitle(trackIndex) {
    if (trackIndex < 0 || trackIndex >= this.subtitleTracks.length) {
      return false;
    }

    const track = this.subtitleTracks[trackIndex];
    const url = `../api/get_subtitle.php?video_id=${this.videoId}&index=${trackIndex}&token=${this.sessionToken}`;

    try {
      const response = await fetch(url);
      if (!response.ok) throw new Error("Failed to load subtitle");

      const vttText = await response.text();
      const videoEl = document.getElementById("secure-video");

      // Remove ALL existing subtitle tracks
      const existingTracks = videoEl.querySelectorAll(
        'track[kind="subtitles"]',
      );
      existingTracks.forEach((t) => t.remove());

      // Create blob URL for VTT content
      const blob = new Blob([vttText], { type: "text/vtt" });
      const blobUrl = URL.createObjectURL(blob);

      // Create new track element
      const trackEl = document.createElement("track");
      trackEl.kind = "subtitles";
      trackEl.label = track.title || track.language;
      trackEl.srclang = track.language || "en";
      trackEl.src = blobUrl;
      trackEl.default = true;

      videoEl.appendChild(trackEl);

      // Wait for track to load
      await new Promise((resolve) => {
        trackEl.addEventListener("load", resolve, { once: true });
      });

      // Enable the track
      trackEl.track.mode = "showing";
      this.currentSubtitle = trackIndex;

      console.log(`📝 Loaded subtitle: ${track.title}`);
      this.dispatchEvent("subtitleLoaded", { index: trackIndex, track: track });

      return true;
    } catch (error) {
      console.error("Failed to load subtitle:", error);
      return false;
    }
  }

  disableSubtitles() {
    const videoEl = document.getElementById("secure-video");

    // Disable all text tracks
    Array.from(videoEl.textTracks).forEach((t) => {
      t.mode = "disabled";
    });

    // Remove track elements
    const existingTracks = videoEl.querySelectorAll('track[kind="subtitles"]');
    existingTracks.forEach((t) => t.remove());

    this.currentSubtitle = -1;
    console.log("📝 Subtitles disabled");
    this.dispatchEvent("subtitleDisabled", {});
  }

  dispatchEvent(eventName, detail) {
    const event = new CustomEvent(eventName, { detail });
    window.dispatchEvent(event);
  }

  async restart(videoElement) {
    console.log("🔄 Restarting playback");

    this.stopChunkLoader();
    this.fetchController.abort();
    this.fetchController = new AbortController();

    this.clearQualityChunks(this.currentQuality);
    this.chunkQueue = [];
    this.processingQueue = false;
    this.streamEnded = false;

    await this.clearBuffers();
    await this.appendInitSegments();

    videoElement.currentTime = 0;
    this.startChunkLoader();

    console.log("✅ Playback restarted");
  }

  async hardRestart(videoId) {
    console.warn("💣 Hard restart: recreating video + MediaSource");

    // Stop loaders
    this.stopChunkLoader();
    this.fetchController.abort();

    // Cleanup decryptor
    this.cleanup();

    // Destroy old video
    const oldVideo = document.getElementById("secure-video");
    oldVideo.pause();
    oldVideo.removeAttribute("src");
    oldVideo.load();

    // Replace video element
    const newVideo = oldVideo.cloneNode(false);
    oldVideo.replaceWith(newVideo);

    // Recreate decryptor
    const decryptor = new VideoDecryptor();
    await decryptor.init(videoId);
    await decryptor.prepareMediaSource(newVideo);

    return decryptor;
  }

  // ================================================
  // UTILITY METHODS
  // ================================================

  detectMobile() {
    return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
      navigator.userAgent,
    );
  }

  isFirefox() {
    return navigator.userAgent.toLowerCase().includes("firefox");
  }

  selectBestQuality() {
    const screenHeight = window.screen.height;
    const qualities = ["1080p", "720p", "480p", "360p", "240p", "144p"];

    for (const quality of qualities) {
      if (this.availableQualities.includes(quality)) {
        const preset = this.getQualityPreset(quality);
        if (preset && screenHeight >= preset.height) {
          return quality;
        }
      }
    }

    return this.availableQualities[0] || "360p";
  }

  getQualityPreset(quality) {
    const presets = {
      "144p": { height: 144, bandwidth: 120000 },
      "240p": { height: 240, bandwidth: 300000 },
      "360p": { height: 360, bandwidth: 800000 },
      "480p": { height: 480, bandwidth: 1400000 },
      "720p": { height: 720, bandwidth: 2800000 },
      "1080p": { height: 1080, bandwidth: 5000000 },
    };

    return presets[quality];
  }

  async waitForBufferUpdate(buffer) {
    return new Promise((resolve) => {
      if (!buffer.updating) {
        resolve();
      } else {
        const onUpdateEnd = () => {
          buffer.removeEventListener("updateend", onUpdateEnd);
          resolve();
        };
        buffer.addEventListener("updateend", onUpdateEnd);
      }
    });
  }

  // ================================================
  // DRM & SECURITY
  // ================================================

  async getEphemeralKey(chunkIndex) {
    const response = await fetch(
      `../api/get_chunk_key.php?` +
        `video_id=${encodeURIComponent(this.videoId)}` +
        `&chunk_index=${chunkIndex}` +
        `&session_token=${encodeURIComponent(this.sessionToken)}`,
    );

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const keyData = await response.json();

    if (!keyData.success) {
      throw new Error(keyData.error || "Failed to get ephemeral key");
    }

    return keyData;
  }

  async decryptWithEphemeralKey(encryptedData, keyData) {
    const key = await window.crypto.subtle.importKey(
      "raw",
      this.base64ToArrayBuffer(keyData.key),
      { name: "AES-CTR", length: 256 },
      false,
      ["decrypt"],
    );

    const iv = this.base64ToArrayBuffer(keyData.iv);

    const decrypted = await window.crypto.subtle.decrypt(
      {
        name: "AES-CTR",
        counter: iv,
        length: 64,
      },
      key,
      encryptedData,
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
        const videoElement = document.getElementById("secure-video");
        if (!videoElement || videoElement.paused || videoElement.ended) {
          return;
        }

        const currentTime =
          videoElement && isFinite(videoElement.currentTime)
            ? videoElement.currentTime
            : 0;

        this.heartbeatAbort?.abort();
        this.heartbeatAbort = new AbortController();

        const response = await fetch("../api/playback_heartbeat.php", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          signal: this.heartbeatAbort.signal,
          body: `token=${encodeURIComponent(this.sessionToken)}&current_time=${currentTime}`,
        });

        if (!response.ok) {
          throw new Error("Heartbeat failed");
        }

        this.heartBeatRetry = 0;
      } catch (error) {
        console.error("Heartbeat error:", error);
        this.heartBeatRetry++;
        if (this.heartBeatRetry > 3) {
          clearInterval(this.heartbeatInterval);
        }
      }
    }, this.config.heartbeatInterval);
  }

  // ================================================
  // CLEANUP & RECOVERY
  // ================================================

  endStream() {
    if (this.streamEnded) return;

    // Firefox: do NOT call endOfStream (causes issues)
    if (this.isFirefox()) {
      this.streamEnded = true;
      console.log("🏁 Stream ended (Firefox - no endOfStream call)");
      return;
    }

    // Other browsers: call endOfStream
    if (this.mediaSource && this.mediaSource.readyState === "open") {
      try {
        this.mediaSource.endOfStream();
        this.streamEnded = true;
        console.log("🏁 Stream ended");
      } catch (e) {
        console.warn("endOfStream failed:", e);
      }
    }
  }

  async attemptRecovery() {
    console.log("🔧 Attempting recovery...");
    this.stats.recoveryAttempts++;

    try {
      const videoElement = document.getElementById("secure-video");
      const currentTime = videoElement.currentTime;

      await this.restart(videoElement);

      if (currentTime > 0) {
        await this.seek(currentTime);
      }

      console.log("✅ Recovery successful");
    } catch (error) {
      console.error("❌ Recovery failed:", error);
      throw error;
    }
  }

  cleanup() {
    console.log("🧹 Cleaning up decryptor");

    this.stopChunkLoader();

    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
      this.heartbeatInterval = null;
    }

    if (this.fetchController) {
      this.fetchController.abort();
      this.fetchController = null;
    }

    this.chunkQueue = [];
    this.chunkState.video.clear();
    this.chunkState.audio.clear();
    this.processingQueue = false;

    if (this.mediaSource && this.mediaSource.readyState === "open") {
      try {
        if (!this.isFirefox()) {
          this.mediaSource.endOfStream();
        }
      } catch (e) {
        // Ignore
      }
    }

    this.isInitialized = false;
    console.log("✅ Cleanup complete");
  }

  getStatus() {
    return {
      initialized: this.isInitialized,
      videoId: this.videoId,
      currentQuality: this.currentQuality,
      currentAudioTrack: this.currentAudioTrack,
      qualityState: this.currentQualityState,
      videoChunksTracked: this.chunkState.video.size,
      audioChunksTracked: this.chunkState.audio.size,
      queueLength: this.chunkQueue.length,
      streamEnded: this.streamEnded,
      isSeeking: this.isSeeking,
      qualitySwitching: this.qualitySwitching,
      audioSwitching: this.audioSwitching,
      stats: this.stats,
    };
  }
}

// Export
if (typeof module !== "undefined" && module.exports) {
  module.exports = EnhancedVideoDecryptor;
} else {
  window.VideoDecryptor = EnhancedVideoDecryptor;
}

// Debug helper
window.debugDecryptor = function () {
  if (window.videoDecryptor) {
    console.table(window.videoDecryptor.getStatus());
  } else {
    console.log("VideoDecryptor not initialized");
  }
};

// Cleanup on page unload
window.addEventListener("beforeunload", () => {
  if (window.videoDecryptor) {
    window.videoDecryptor.cleanup();
  }
});

console.log("🔐 Enhanced VideoDecryptor V2 loaded");
