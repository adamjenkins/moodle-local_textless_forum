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

namespace local_textless_forum;

/**
 * Checks and stores a recording uploaded from the browser recorder.
 *
 * Every rule the recorder applies in the browser is applied here again,
 * because a request can be sent without the recorder: the forum must be
 * textless, the user must be able to post in it, the recording type must be
 * allowed by the forum, the file must really be an audio or video container
 * of that type, and it must fit the upload size limit and the forum's
 * maximum recording length.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording {
    /** @var string Matroska/WebM container (Chrome, Edge, Firefox). */
    const CONTAINER_MATROSKA = 'matroska';

    /** @var string ISO base media (MP4) container (Safari, newer Chrome). */
    const CONTAINER_MP4 = 'mp4';

    /** @var string Ogg container (Firefox audio). */
    const CONTAINER_OGG = 'ogg';

    /** @var int How many bytes at each end of a file are inspected for track information. */
    const SNIFF_BYTES = 1048576;

    /**
     * @var int Allowance on top of the bitrate budget, in bytes, for container
     * headers and short recordings.
     */
    const SIZE_OVERHEAD = 262144;

    /** @var int The bitrate budget is this many times the requested bitrate. */
    const BITRATE_SLACK = 2;

    /**
     * Identify the media container of a file from its first bytes.
     *
     * The extension and the browser-declared type are chosen by the client, so
     * only the content is trusted here.
     *
     * @param string $path the file to inspect
     * @return string|null one of the CONTAINER_* constants, or null when the file is not a supported container
     */
    public static function detect_container(string $path): ?string {
        $head = (string) @file_get_contents($path, false, null, 0, 12);

        if (strlen($head) >= 4 && substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
            return self::CONTAINER_MATROSKA;
        }
        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') {
            return self::CONTAINER_MP4;
        }
        if (strlen($head) >= 4 && substr($head, 0, 4) === 'OggS') {
            return self::CONTAINER_OGG;
        }

        return null;
    }

    /**
     * Whether a media file contains a video track.
     *
     * Only the container's track headers are read, so the media payload cannot
     * produce a false match: for Matroska the headers before the first Cluster,
     * for MP4 the handler ('hdlr') boxes, for Ogg the stream header packets.
     *
     * @param string $path the file to inspect
     * @param string $container one of the CONTAINER_* constants
     * @return bool true when a video track was found
     */
    public static function has_video_track(string $path, string $container): bool {
        $head = (string) @file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        switch ($container) {
            case self::CONTAINER_MATROSKA:
                // Tracks always precede the first Cluster in recorder output.
                $cluster = strpos($head, "\x1F\x43\xB6\x75");
                $headers = $cluster === false ? $head : substr($head, 0, $cluster);
                return (bool) preg_match('~V_(?:VP8|VP9|AV1|MPEG4/|MPEGH/|THEORA)~', $headers);

            case self::CONTAINER_MP4:
                // The 'moov' box can sit before or after the media data.
                $size = (int) @filesize($path);
                $tail = '';
                if ($size > self::SNIFF_BYTES) {
                    $tail = (string) @file_get_contents($path, false, null, $size - self::SNIFF_BYTES);
                }
                $mdat = strpos($head, 'mdat');
                $headers = $mdat === false ? $head : substr($head, 0, $mdat);
                return (bool) preg_match('~hdlr.{8}vide~s', $headers . $tail);

            case self::CONTAINER_OGG:
                return strpos($head, "\x80theora") !== false || strpos($head, "OVP80") !== false;
        }

        return false;
    }

    /**
     * The file extension a recording in the given container is stored with.
     *
     * @param string $container one of the CONTAINER_* constants
     * @return string the extension, without a dot
     */
    public static function extension_for(string $container): string {
        switch ($container) {
            case self::CONTAINER_MP4:
                return 'mp4';
            case self::CONTAINER_OGG:
                return 'ogg';
            default:
                return 'webm';
        }
    }

    /**
     * The largest recording, in bytes, a forum's maximum length allows.
     *
     * Recordings cannot be measured on the web server without running a
     * media tool, and spawning a process on a web request is not safe on
     * every runtime (see {@see transcoder::is_ffmpeg_available()}), so the
     * length is bounded through the size instead: the maximum length at
     * {@see BITRATE_SLACK} times the recorder's bitrate (never less than the
     * default bitrate), plus {@see SIZE_OVERHEAD}.
     *
     * @param int $maxduration the forum's maximum length in seconds, 0 for no limit
     * @param string $mediatype manager::MODE_AUDIO or manager::MODE_VIDEO
     * @return int the size limit in bytes, or 0 for no limit
     */
    public static function max_bytes_for_duration(int $maxduration, string $mediatype): int {
        if ($maxduration <= 0) {
            return 0;
        }

        $bitrate = max(manager::get_audio_bitrate(), manager::DEFAULT_AUDIO_BITRATE);
        if ($mediatype === manager::MODE_VIDEO) {
            $bitrate += max(manager::get_video_bitrate(), manager::DEFAULT_VIDEO_BITRATE);
        }

        return (int) ($maxduration * $bitrate / 8 * self::BITRATE_SLACK) + self::SIZE_OVERHEAD;
    }

    /**
     * Check an uploaded recording against the forum's rules and store it in
     * the current user's draft area.
     *
     * @param \context $context the forum module context the recording is for
     * @param int $draftitemid the editor's draft item id
     * @param string $mediatype the recording type the client declares: 'audio' or 'video'
     * @param string $path the uploaded file
     * @return \stored_file the stored recording
     * @throws \moodle_exception when any rule is broken
     */
    public static function store(\context $context, int $draftitemid, string $mediatype, string $path): \stored_file {
        global $CFG, $DB, $USER;

        if ($context->contextlevel != CONTEXT_MODULE) {
            throw new \moodle_exception('invalidcontext', 'error');
        }

        // Resolve the forum behind this module context and confirm the user may post here.
        $cm = get_coursemodule_from_id('forum', $context->instanceid, 0, false, MUST_EXIST);
        require_login($cm->course, false, $cm, false, true);
        $forumid = (int) $cm->instance;

        if (!manager::is_textless($forumid)) {
            throw new \moodle_exception('nottextless', 'local_textless_forum');
        }

        // The user must be able to contribute to this forum in some way.
        if (
            !has_capability('mod/forum:replypost', $context)
                && !has_capability('mod/forum:startdiscussion', $context)
        ) {
            throw new \moodle_exception('nopermissions', 'error', '', get_string('reply', 'mod_forum'));
        }

        // The declared type has to be acceptable for the configured mode...
        $mode = manager::get_mode($forumid);
        if (
            !in_array($mediatype, [manager::MODE_AUDIO, manager::MODE_VIDEO], true)
                || ($mode !== manager::MODE_BOTH && $mode !== $mediatype)
        ) {
            throw new \moodle_exception('recordingnotallowed', 'local_textless_forum');
        }

        // ...and the file itself has to be a recording of that type.
        $container = self::detect_container($path);
        if ($container === null) {
            throw new \moodle_exception('recordingnotmedia', 'local_textless_forum');
        }
        if (self::has_video_track($path, $container) !== ($mediatype === manager::MODE_VIDEO)) {
            throw new \moodle_exception('recordingnotallowed', 'local_textless_forum');
        }

        // Size: the site, course and forum upload limits, then the forum's maximum length.
        $size = (int) filesize($path);
        $course = get_course($cm->course);
        $forummaxbytes = (int) $DB->get_field('forum', 'maxbytes', ['id' => $forumid]);
        $maxbytes = get_user_max_upload_file_size($context, $CFG->maxbytes, $course->maxbytes, $forummaxbytes);
        if ($maxbytes != USER_CAN_IGNORE_FILE_SIZE_LIMITS && $size > $maxbytes) {
            throw new \moodle_exception('maxbytesfile', 'error', '', (object) [
                'file' => get_string('recordingfile', 'local_textless_forum'),
                'size' => display_size($maxbytes),
            ]);
        }
        $durationbytes = self::max_bytes_for_duration(manager::get_max_duration($forumid), $mediatype);
        if ($durationbytes && $size > $durationbytes) {
            throw new \moodle_exception('recordingtoolong', 'local_textless_forum');
        }

        // The name is built here, so the "audio-"/"video-" prefix the
        // transcoder relies on always matches the checked content.
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $filename = $mediatype . '-' . time() . '-' . random_string(6) . '.' . self::extension_for($container);
        $filename = $fs->get_unused_filename($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);

        $filerecord = (object) [
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $USER->id,
        ];

        return $fs->create_file_from_pathname($filerecord, $path);
    }
}
