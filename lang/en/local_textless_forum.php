<?php
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
 * Language strings for local_textless_forum.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Textless forum';

// Site-wide admin settings.
$string['qualityheader'] = 'Recording quality';
$string['qualityheader_desc'] = 'These settings control the quality (and therefore file size) of recordings made in textless forums, by setting the bitrates requested from the browser\'s recorder.';
$string['audiobitrate'] = 'Audio bitrate';
$string['audiobitrate_desc'] = 'Quality of recorded audio (larger number means higher quality and a larger file). Applies to both audio and video recordings.';
$string['videobitrate'] = 'Video bitrate';
$string['videobitrate_desc'] = 'Quality of recorded video (larger number means higher quality and a larger file). Applies to video recordings only.';
$string['kbrate'] = '{$a} kb/s';

$string['transcodeheader'] = 'Recording transcoding';
$string['transcodeheader_desc'] = 'These settings control whether recordings uploaded to textless forums are converted to other, more widely compatible formats after they are saved.';
$string['transcodeenabled'] = 'Transcode recordings';
$string['transcodeenabled_desc'] = 'When enabled, recordings posted to textless forums are also converted to the formats selected below (in addition to being kept in their original format) once the post is saved, using ffmpeg in the background.';
$string['transcodeaudioformat'] = 'Audio format';
$string['transcodeaudioformat_desc'] = 'Audio recordings are also converted to this format, for wider playback compatibility. Choose "Don\'t convert" to leave audio recordings as recorded.';
$string['transcodevideoformat'] = 'Video format';
$string['transcodevideoformat_desc'] = 'Video recordings are also converted to this format, for wider playback compatibility. Choose "Don\'t convert" to leave video recordings as recorded.';
$string['transcodeformatnone'] = "Don't convert";
$string['format_mp3'] = 'MP3';
$string['format_mp4'] = 'MP4 (H.264/AAC)';
$string['ffmpegpath'] = 'Path to ffmpeg';
$string['ffmpegpath_desc'] = 'The full path to the ffmpeg executable on the server, used to transcode recordings. This is a system executable path, so it is locked when $CFG->preventexecpath is enabled.';
$string['ffmpegmissing'] = 'Recordings cannot be transcoded because the command "{$a}" could not be run on this server. Transcoding has been switched off. Install ffmpeg (or correct the path above) to enable it.';
$string['transcodetaskname'] = 'Transcode textless forum recording';

// Activity settings.
$string['settingsheader'] = 'Textless forum (RecordRTC only)';
$string['enable'] = 'Make this forum textless';
$string['enable_desc'] = 'Replace the message editor with a RecordRTC recorder so posts can only contain a recording.';
$string['enable_help'] = 'When enabled, the message editor for this forum is replaced with a RecordRTC recorder. The subject can still be typed, but the message itself must be a recorded audio or video clip. Typing and all other editor tools are disabled, and the in-page "Reply" buttons are routed to the recorder.';
$string['mode'] = 'Allowed recording type';
$string['mode_help'] = 'Choose whether participants may post audio, video, or either. This applies to new discussions, replies and edits in this forum.';
$string['mode_both'] = 'Audio or video';
$string['mode_audio'] = 'Audio only';
$string['mode_video'] = 'Video only';
$string['maxduration'] = 'Maximum recording length';
$string['maxduration_help'] = 'The longest a single recording may be. Recording stops automatically once this length is reached. Set to 0 seconds for no limit.';

// Recorder interface.
$string['recorderintro'] = 'Record your message. Typing is disabled in this forum — your message must be a recording.';
$string['recordaudio'] = 'Record audio';
$string['recordvideo'] = 'Record video';
$string['startrecording'] = 'Start recording';
$string['cancelpreview'] = 'Cancel';
$string['stoprecording'] = 'Stop recording';
$string['rerecord'] = 'Record again';
$string['previewready'] = 'Camera ready. Click "Start recording" when you are ready to begin — the camera is not recording yet.';
$string['recording'] = 'Recording… click "Stop recording" when you have finished.';
$string['recordingstopped'] = 'Recording stopped automatically — the maximum length was reached.';
$string['maxlength'] = 'Maximum length: {$a}';
$string['timeremaininglabel'] = 'Time remaining';
$string['uploading'] = 'Uploading your recording…';
$string['recorded'] = 'Recording ready. You can post it or record again.';

// Recorder errors.
$string['errornopermission'] = 'Could not access your microphone or camera. Please grant permission and try again.';
$string['errorunsupported'] = 'Recording is not supported by this browser.';
$string['erroruploadfailed'] = 'The recording could not be uploaded. Please try again.';

// Server-side errors.
$string['nottextless'] = 'This forum is not configured as a textless forum.';
$string['recordingnotallowed'] = 'That type of recording is not allowed in this forum.';
$string['norecordingfound'] = 'No recording was received.';
$string['uploadfailed'] = 'The recording upload failed.';

// Privacy.
$string['privacy:metadata'] = 'The Textless forum plugin only stores per-forum configuration. Recordings are stored against the forum post by the Forum activity itself.';
