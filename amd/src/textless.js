// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Enforces the RecordRTC-only restriction on textless forums.
 *
 * It replaces the forum message editor with a recorder that can only capture
 * audio and/or video, and reroutes in-page reply buttons to the advanced
 * editor so every way of posting obeys the same restriction.
 *
 * @module     local_textless_forum/textless
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {renderForPromise, appendNodeContents} from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    messageTextarea: 'textarea[name="message[text]"]',
    messageFormat: '[name="message[format]"]',
    messageItemid: 'input[name="message[itemid]"]',
    replyButton: '[data-action="create-inpage-reply"], a[data-action="collapsible-link"][data-post-id]',
    felement: '.felement',
    recorder: '[data-region="textless-recorder"]',
    status: '[data-region="status"]',
    limitInfo: '[data-region="limit-info"]',
    countdown: '[data-region="countdown"]',
    previewWrapper: '[data-region="preview-wrapper"]',
    preview: '[data-region="preview"]',
    playback: '[data-region="playback"]',
    recordAudio: '[data-action="record-audio"]',
    recordVideo: '[data-action="record-video"]',
    startRecording: '[data-action="start-recording"]',
    switchCamera: '[data-action="switch-camera"]',
    cancelPreview: '[data-action="cancel-preview"]',
    stop: '[data-action="stop"]',
    rerecord: '[data-action="rerecord"]',
};

/**
 * Pick a sensible file extension for the recorded MIME type.
 *
 * @param {String} mime The MIME type reported by the recorder.
 * @returns {String} A file extension without the leading dot.
 */
const extensionForMime = (mime) => {
    if (!mime) {
        return 'webm';
    }
    if (mime.indexOf('mp4') !== -1) {
        return 'mp4';
    }
    if (mime.indexOf('ogg') !== -1) {
        return 'ogg';
    }
    return 'webm';
};

/**
 * Format a number of seconds as a "minutes:seconds" string for display.
 *
 * @param {Number} totalSeconds The duration in seconds.
 * @returns {String} The duration formatted as M:SS.
 */
const formatDuration = (totalSeconds) => {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = Math.floor(totalSeconds % 60);
    return `${minutes}:${seconds < 10 ? '0' : ''}${seconds}`;
};

/**
 * Controls a single message editor once it has been locked down.
 */
class Recorder {
    /**
     * @param {HTMLElement} recorder The rendered recorder container.
     * @param {HTMLTextAreaElement} textarea The hidden message textarea.
     * @param {HTMLFormElement} form The owning form.
     * @param {Object} config The init configuration (contextid, mode).
     */
    constructor(recorder, textarea, form, config) {
        this.recorder = recorder;
        this.textarea = textarea;
        this.form = form;
        this.config = config;
        this.mediaRecorder = null;
        this.stream = null;
        this.chunks = [];
        this.mediaType = null;
        this.embed = '';
        this.videoDevices = [];
        this.deviceIndex = -1;
        this.autoStopTimer = null;
        this.autoStopped = false;
        this.countdownTimer = null;
        this.countdownLabel = '';
        this.recordingEndsAt = 0;

        this.statusEl = recorder.querySelector(SELECTORS.status);
        this.limitInfo = recorder.querySelector(SELECTORS.limitInfo);
        this.countdown = recorder.querySelector(SELECTORS.countdown);
        this.previewWrapper = recorder.querySelector(SELECTORS.previewWrapper);
        this.preview = recorder.querySelector(SELECTORS.preview);
        this.playback = recorder.querySelector(SELECTORS.playback);
        this.audioBtn = recorder.querySelector(SELECTORS.recordAudio);
        this.videoBtn = recorder.querySelector(SELECTORS.recordVideo);
        this.startRecordingBtn = recorder.querySelector(SELECTORS.startRecording);
        this.switchCameraBtn = recorder.querySelector(SELECTORS.switchCamera);
        this.cancelPreviewBtn = recorder.querySelector(SELECTORS.cancelPreview);
        this.stopBtn = recorder.querySelector(SELECTORS.stop);
        this.rerecordBtn = recorder.querySelector(SELECTORS.rerecord);

        this.registerListeners();
        this.showLimitInfo();
    }

    /**
     * Display the forum's configured maximum recording length, and preload the
     * label used by the live countdown shown while recording. A maxduration of
     * 0 means recordings are not limited, so nothing is shown.
     */
    async showLimitInfo() {
        if (!this.config.maxduration || !this.limitInfo) {
            return;
        }

        const [limitText, countdownLabel] = await Promise.all([
            getString('maxlength', 'local_textless_forum', formatDuration(this.config.maxduration)),
            getString('timeremaininglabel', 'local_textless_forum'),
        ]);

        this.limitInfo.textContent = limitText;
        this.limitInfo.classList.remove('d-none');
        this.countdownLabel = countdownLabel;
    }

    /**
     * Wire up the control buttons.
     */
    registerListeners() {
        if (this.audioBtn) {
            this.audioBtn.addEventListener('click', () => this.start('audio'));
        }
        if (this.videoBtn) {
            this.videoBtn.addEventListener('click', () => this.start('video'));
        }
        if (this.startRecordingBtn) {
            this.startRecordingBtn.addEventListener('click', () => this.beginRecording());
        }
        if (this.switchCameraBtn) {
            this.switchCameraBtn.addEventListener('click', () => this.switchCamera());
        }
        if (this.cancelPreviewBtn) {
            this.cancelPreviewBtn.addEventListener('click', () => this.cancelPreview());
        }
        this.stopBtn.addEventListener('click', () => this.stop());
        this.rerecordBtn.addEventListener('click', () => this.reset());

        // Guarantee our recording survives submission regardless of editor timing:
        // remove any TinyMCE instance (its removal would otherwise resync the
        // textarea) and then write the embed value back.
        this.form.addEventListener('submit', () => {
            if (!this.embed) {
                return;
            }
            if (window.tinymce && this.textarea.id) {
                const editor = window.tinymce.get(this.textarea.id);
                if (editor) {
                    editor.remove();
                }
            }
            this.textarea.value = this.embed;
        }, true);
    }

    /**
     * Show a status message to the user.
     *
     * @param {String} key The language string identifier.
     */
    async setStatus(key) {
        this.statusEl.textContent = await getString(key, 'local_textless_forum');
    }

    /**
     * Toggle which buttons are visible.
     *
     * @param {String} state One of 'idle', 'previewing', 'recording' or 'recorded'.
     */
    setButtons(state) {
        const showRecord = state === 'idle';
        if (this.audioBtn) {
            this.audioBtn.classList.toggle('d-none', !showRecord);
        }
        if (this.videoBtn) {
            this.videoBtn.classList.toggle('d-none', !showRecord);
        }
        if (this.startRecordingBtn) {
            this.startRecordingBtn.classList.toggle('d-none', state !== 'previewing');
        }
        if (this.switchCameraBtn) {
            this.switchCameraBtn.classList.toggle('d-none', state !== 'previewing' || this.videoDevices.length < 2);
        }
        if (this.cancelPreviewBtn) {
            this.cancelPreviewBtn.classList.toggle('d-none', state !== 'previewing');
        }
        this.stopBtn.classList.toggle('d-none', state !== 'recording');
        this.rerecordBtn.classList.toggle('d-none', state !== 'recorded');
    }

    /**
     * Start capturing media of the given type.
     *
     * For audio this begins recording immediately. For video the camera is
     * switched on and previewed first, and the user must press "Start
     * recording" separately — this lets them frame themselves before the
     * recording (and its time limit) begins.
     *
     * @param {String} type Either 'audio' or 'video'.
     */
    async start(type) {
        this.mediaType = type;
        this.chunks = [];
        this.clearPlayback();

        const constraints = type === 'video' ? {audio: true, video: true} : {audio: true};

        try {
            this.stream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (e) {
            this.setStatus('errornopermission');
            return;
        }

        if (type === 'video') {
            await this.detectCameras();
            this.preview.srcObject = this.stream;
            this.previewWrapper.classList.remove('d-none');
            this.preview.play().catch(() => {
                return;
            });
            this.setButtons('previewing');
            this.setStatus('previewready');
            return;
        }

        this.beginRecording();
    }

    /**
     * Look for the available cameras and remember which one is currently in
     * use, so {@see switchCamera} can offer the user the next one.
     *
     * Devices can only be enumerated (with usable labels and ids) once
     * permission has been granted, so this is called after the first
     * getUserMedia() call.
     */
    async detectCameras() {
        this.videoDevices = [];
        this.deviceIndex = -1;

        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            return;
        }

        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            this.videoDevices = devices.filter((device) => device.kind === 'videoinput');
        } catch (e) {
            this.videoDevices = [];
            return;
        }

        const track = this.stream && this.stream.getVideoTracks()[0];
        const currentId = track && track.getSettings && track.getSettings().deviceId;
        const matchedIndex = currentId
            ? this.videoDevices.findIndex((device) => device.deviceId === currentId)
            : -1;
        this.deviceIndex = matchedIndex !== -1 ? matchedIndex : 0;
    }

    /**
     * Switch the camera preview to the next available video input device,
     * cycling back to the first once the last is reached. Only the video
     * track is replaced; the microphone keeps recording from the same source.
     */
    async switchCamera() {
        if (this.videoDevices.length < 2) {
            return;
        }

        const nextIndex = (this.deviceIndex + 1) % this.videoDevices.length;
        const deviceId = this.videoDevices[nextIndex].deviceId;

        let newStream;
        try {
            newStream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: {deviceId: {exact: deviceId}},
            });
        } catch (e) {
            this.setStatus('errornopermission');
            return;
        }

        const newTrack = newStream.getVideoTracks()[0];
        const oldTrack = this.stream.getVideoTracks()[0];
        if (oldTrack) {
            this.stream.removeTrack(oldTrack);
            oldTrack.stop();
        }
        this.stream.addTrack(newTrack);

        this.preview.srcObject = this.stream;
        this.preview.play().catch(() => {
            return;
        });

        this.deviceIndex = nextIndex;
    }

    /**
     * Start recording from the already-acquired media stream.
     *
     * Called immediately for audio, or once the user confirms they are ready
     * after previewing the camera for video.
     */
    beginRecording() {
        const options = {};
        if (this.config.audiobitrate) {
            options.audioBitsPerSecond = this.config.audiobitrate;
        }
        if (this.mediaType === 'video' && this.config.videobitrate) {
            options.videoBitsPerSecond = this.config.videobitrate;
        }

        try {
            this.mediaRecorder = new MediaRecorder(this.stream, options);
        } catch (e) {
            this.setStatus('errorunsupported');
            this.cancelPreview();
            return;
        }

        this.mediaRecorder.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size > 0) {
                this.chunks.push(e.data);
            }
        });
        this.mediaRecorder.addEventListener('stop', () => this.onStop());
        this.mediaRecorder.start();

        this.scheduleAutoStop();
        this.startCountdown();

        this.setButtons('recording');
        this.setStatus('recording');
    }

    /**
     * Abandon a camera preview without recording, switching the camera off
     * and returning to the idle state.
     */
    cancelPreview() {
        this.stopStream();
        this.previewWrapper.classList.add('d-none');
        this.preview.srcObject = null;
        this.mediaType = null;
        this.videoDevices = [];
        this.deviceIndex = -1;
        this.setButtons('idle');
        this.setStatus('recorderintro');
    }

    /**
     * Schedule the recording to stop automatically once it reaches the
     * forum's configured maximum length. A maxduration of 0 means no limit.
     */
    scheduleAutoStop() {
        if (!this.config.maxduration) {
            return;
        }
        this.autoStopTimer = setTimeout(() => {
            this.autoStopped = true;
            this.stop();
        }, this.config.maxduration * 1000);
    }

    /**
     * Cancel a pending auto-stop, for example when the user stops manually.
     */
    clearAutoStop() {
        if (this.autoStopTimer) {
            clearTimeout(this.autoStopTimer);
            this.autoStopTimer = null;
        }
    }

    /**
     * Start showing a live countdown of the time remaining before the
     * recording is stopped automatically. Replaces the static "maximum
     * length" notice for the duration of the recording.
     */
    startCountdown() {
        if (!this.config.maxduration || !this.countdown) {
            return;
        }

        this.recordingEndsAt = Date.now() + (this.config.maxduration * 1000);
        if (this.limitInfo) {
            this.limitInfo.classList.add('d-none');
        }
        this.countdown.classList.remove('d-none');
        this.updateCountdown();
        this.countdownTimer = setInterval(() => this.updateCountdown(), 250);
    }

    /**
     * Refresh the live countdown to reflect the time remaining.
     */
    updateCountdown() {
        const remaining = Math.max(0, (this.recordingEndsAt - Date.now()) / 1000);
        this.countdown.textContent = `${this.countdownLabel}: ${formatDuration(remaining)}`;
    }

    /**
     * Stop the live countdown and restore the static "maximum length" notice.
     */
    stopCountdown() {
        if (this.countdownTimer) {
            clearInterval(this.countdownTimer);
            this.countdownTimer = null;
        }
        if (!this.countdown) {
            return;
        }
        this.countdown.classList.add('d-none');
        if (this.config.maxduration && this.limitInfo) {
            this.limitInfo.classList.remove('d-none');
        }
    }

    /**
     * Stop the active recording.
     */
    stop() {
        this.clearAutoStop();
        this.stopCountdown();
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
            this.mediaRecorder.stop();
        }
        this.stopStream();
    }

    /**
     * Stop and release all media tracks.
     */
    stopStream() {
        if (this.stream) {
            this.stream.getTracks().forEach((track) => track.stop());
            this.stream = null;
        }
    }

    /**
     * Handle the end of a recording: preview it locally, then upload and embed it.
     */
    async onStop() {
        const type = this.mediaType;
        const mime = (this.chunks[0] && this.chunks[0].type)
            || (this.mediaRecorder && this.mediaRecorder.mimeType)
            || (type === 'video' ? 'video/webm' : 'audio/webm');
        const blob = new Blob(this.chunks, {type: mime});

        this.showPlayback(blob, type);
        this.previewWrapper.classList.add('d-none');
        this.preview.srcObject = null;
        this.setButtons('recorded');

        if (this.autoStopped) {
            this.autoStopped = false;
            await this.setStatus('recordingstopped');
        }

        try {
            await this.setStatus('uploading');
            const url = await this.upload(blob, type, mime);
            this.setEmbed(url, type);
            await this.setStatus('recorded');
        } catch (e) {
            this.setStatus('erroruploadfailed');
        }
    }

    /**
     * Show a local playback of the recording before upload completes.
     *
     * @param {Blob} blob The recorded media.
     * @param {String} type Either 'audio' or 'video'.
     */
    showPlayback(blob, type) {
        this.clearPlayback();
        const player = document.createElement(type === 'video' ? 'video' : 'audio');
        player.controls = true;
        player.src = URL.createObjectURL(blob);
        if (type === 'video') {
            player.classList.add('w-100', 'rounded');
        }
        this.playback.appendChild(player);
        this.playback.classList.remove('d-none');
    }

    /**
     * Remove any existing playback element.
     */
    clearPlayback() {
        this.playback.innerHTML = '';
        this.playback.classList.add('d-none');
    }

    /**
     * Upload the recorded blob to the draft area used by the editor.
     *
     * @param {Blob} blob The recorded media.
     * @param {String} type Either 'audio' or 'video'.
     * @param {String} mime The MIME type of the recording.
     * @returns {Promise<String>} The draftfile URL of the stored recording.
     */
    async upload(blob, type, mime) {
        const itemidEl = this.form.querySelector(SELECTORS.messageItemid);
        const itemid = itemidEl ? itemidEl.value : 0;
        const filename = `${type}-${Date.now()}.${extensionForMime(mime)}`;

        const formData = new FormData();
        formData.append('sesskey', M.cfg.sesskey);
        formData.append('itemid', itemid);
        formData.append('contextid', this.config.contextid);
        formData.append('mediatype', type);
        formData.append('recording', blob, filename);

        const response = await fetch(`${M.cfg.wwwroot}/local/textless_forum/upload.php`, {
            method: 'POST',
            body: formData,
        });
        const data = await response.json();

        if (!data || !data.url) {
            throw new Error('upload failed');
        }

        return data.url;
    }

    /**
     * Write the embed markup into the hidden textarea so the form submits it.
     *
     * @param {String} url The draftfile URL of the recording.
     * @param {String} type Either 'audio' or 'video'.
     */
    setEmbed(url, type) {
        const tag = type === 'video' ? 'video' : 'audio';
        this.embed = `<${tag} controls="true"><source src="${url}"></${tag}>`;
        this.textarea.value = this.embed;
        this.textarea.dispatchEvent(new Event('change', {bubbles: true}));
    }

    /**
     * Reset the recorder so the user can record again.
     */
    reset() {
        this.clearAutoStop();
        this.stopCountdown();
        this.autoStopped = false;
        this.stopStream();
        this.chunks = [];
        this.embed = '';
        this.mediaType = null;
        this.videoDevices = [];
        this.deviceIndex = -1;
        this.textarea.value = '';
        this.textarea.dispatchEvent(new Event('change', {bubbles: true}));
        this.clearPlayback();
        this.previewWrapper.classList.add('d-none');
        this.preview.srcObject = null;
        this.setButtons('idle');
        this.setStatus('recorderintro');
    }
}

/**
 * Remove any TinyMCE instance attached to the textarea so it cannot overwrite
 * our embedded content on submit.
 *
 * @param {HTMLTextAreaElement} textarea The message textarea.
 * @returns {Promise<void>} Resolves once TinyMCE has been removed or timed out.
 */
const destroyTinyMce = (textarea) => new Promise((resolve) => {
    let attempts = 0;
    const tryRemove = () => {
        attempts++;
        if (window.tinymce && textarea.id) {
            const editor = window.tinymce.get(textarea.id);
            if (editor) {
                editor.remove();
                resolve();
                return;
            }
        }
        if (attempts > 50) {
            resolve();
            return;
        }
        setTimeout(tryRemove, 100);
    };
    tryRemove();
});

/**
 * Force the message to be submitted as HTML so the embedded media renders.
 *
 * @param {HTMLFormElement} form The owning form.
 */
const forceHtmlFormat = (form) => {
    const formatEl = form.querySelector(SELECTORS.messageFormat);
    if (formatEl) {
        // FORMAT_HTML is 1.
        formatEl.value = '1';
    }
};

/**
 * Lock down a single message editor and attach a recorder in its place.
 *
 * @param {HTMLTextAreaElement} textarea The message textarea.
 * @param {Object} config The init configuration.
 */
const lockEditor = async(textarea, config) => {
    if (textarea.dataset.textlessReady) {
        return;
    }
    textarea.dataset.textlessReady = '1';

    const form = textarea.closest('form');
    if (!form) {
        return;
    }

    forceHtmlFormat(form);

    const felement = textarea.closest(SELECTORS.felement) || textarea.parentElement;

    // Hide every part of the original editor (including any TinyMCE UI inserted
    // later) via CSS, while keeping the textarea in the DOM for submission.
    felement.classList.add('local-textless-forum-locked');
    textarea.readOnly = true;

    // Tear down any TinyMCE instance in the background so it cannot be interacted with.
    destroyTinyMce(textarea);

    const allowAudio = config.mode === 'audio' || config.mode === 'both';
    const allowVideo = config.mode === 'video' || config.mode === 'both';

    const {html, js} = await renderForPromise('local_textless_forum/recorder', {
        allowaudio: allowAudio,
        allowvideo: allowVideo,
    });

    appendNodeContents(felement, html, js);
    const recorder = felement.querySelector(SELECTORS.recorder);

    new Recorder(recorder, textarea, form, config);
};

/**
 * Reroute in-page reply buttons to the advanced (RecordRTC) editor.
 *
 * Different forum discussion layouts trigger the in-page reply form through
 * different controls: the "nested_v2" layout uses a button carrying
 * data-action="create-inpage-reply" and data-href, while the standard layout
 * uses a plain link with data-action="collapsible-link", data-post-id and a
 * normal href (the same link also doubles as the in-page form's "Cancel"
 * button, but that one is a <button> with neither attribute, so it is not
 * matched here).
 */
const interceptReplies = () => {
    if (document.body.dataset.textlessReplies) {
        return;
    }
    document.body.dataset.textlessReplies = '1';

    document.addEventListener('click', (e) => {
        const button = e.target.closest(SELECTORS.replyButton);
        if (!button) {
            return;
        }
        const href = button.getAttribute('data-href') || button.getAttribute('href');
        if (!href) {
            return;
        }
        // Beat mod_forum's own (bubble phase) handler so the in-page textarea never opens.
        e.preventDefault();
        e.stopImmediatePropagation();
        window.location.href = href;
    }, true);
};

/**
 * Entry point.
 *
 * @param {Object} config The configuration: {contextid, mode}.
 */
export const init = (config) => {
    // Temporary diagnostic — confirms the module loaded and received its config.
    window.console.log('local_textless_forum: enforcing RecordRTC-only editor', config);

    interceptReplies();

    const processAll = () => {
        document.querySelectorAll(SELECTORS.messageTextarea).forEach((textarea) => {
            lockEditor(textarea, config);
        });
    };

    processAll();

    // Catch message editors that are added to the page after we run, for example
    // inline forms loaded via AJAX or editors re-rendered by the forum UI.
    const observer = new MutationObserver(() => processAll());
    observer.observe(document.body, {childList: true, subtree: true});
};
