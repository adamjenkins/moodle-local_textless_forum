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
 * Converts saved recordings to other formats with ffmpeg, in the background.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcoder {
    /** @var string Value meaning "do not convert this kind of recording". */
    const FORMAT_NONE = 'none';

    /** @var bool|null Cached result of {@see is_ffmpeg_available()} for this request. */
    protected static $ffmpegavailable = null;

    /**
     * The ffmpeg command configured by the site administrator.
     *
     * @return string the configured command or path, defaulting to "ffmpeg"
     */
    public static function ffmpeg_path(): string {
        $path = trim((string) get_config('local_textless_forum', 'ffmpegpath'));

        return $path !== '' ? $path : '/usr/bin/ffmpeg';
    }

    /**
     * Whether the configured ffmpeg command can actually be run on this server.
     *
     * The result is cached for the lifetime of the request, since this involves
     * spawning a process and is checked from both the settings page and the
     * transcoding task.
     *
     * @return bool true when "<ffmpeg> -version" runs successfully
     */
    public static function is_ffmpeg_available(): bool {
        if (self::$ffmpegavailable !== null) {
            return self::$ffmpegavailable;
        }

        if (!function_exists('exec')) {
            return self::$ffmpegavailable = false;
        }

        $command = escapeshellarg(self::ffmpeg_path()) . ' -version';
        $output = [];
        $exitcode = 1;
        @exec($command . ' 2>&1', $output, $exitcode);

        return self::$ffmpegavailable = ($exitcode === 0);
    }

    /**
     * Whether transcoding should actually happen: the administrator has turned
     * it on, and the prerequisites (ffmpeg) are satisfied.
     *
     * @return bool true when recordings should be transcoded
     */
    public static function is_enabled(): bool {
        return !empty(get_config('local_textless_forum', 'transcodeenabled')) && self::is_ffmpeg_available();
    }

    /**
     * The format audio recordings should be converted to, or {@see FORMAT_NONE}.
     *
     * @return string the configured target format
     */
    public static function get_audio_format(): string {
        $format = (string) get_config('local_textless_forum', 'transcodeaudioformat');

        return $format !== '' ? $format : self::FORMAT_NONE;
    }

    /**
     * The format video recordings should be converted to, or {@see FORMAT_NONE}.
     *
     * @return string the configured target format
     */
    public static function get_video_format(): string {
        $format = (string) get_config('local_textless_forum', 'transcodevideoformat');

        return $format !== '' ? $format : self::FORMAT_NONE;
    }

    /**
     * Look at the files attached to a saved forum post, and queue a background
     * transcoding task for each recording that is not already in its target format.
     *
     * @param int $postid the mod_forum post id the files are attached to
     * @param int $contextid the forum module context id the files belong to
     * @return void
     */
    public static function queue_for_post(int $postid, int $contextid): void {
        if (!self::is_enabled()) {
            return;
        }

        $audioformat = self::get_audio_format();
        $videoformat = self::get_video_format();
        if ($audioformat === self::FORMAT_NONE && $videoformat === self::FORMAT_NONE) {
            return;
        }

        $fs = get_file_storage();
        foreach ($fs->get_area_files($contextid, 'mod_forum', 'post', $postid, 'filename', false) as $file) {
            $targetformat = self::target_format_for($file, $audioformat, $videoformat);

            if ($targetformat === null || self::matches_format($file, $targetformat)) {
                continue;
            }

            $task = new \local_textless_forum\task\transcode_recording();
            $task->set_custom_data([
                'contextid' => (int) $file->get_contextid(),
                'component' => $file->get_component(),
                'filearea' => $file->get_filearea(),
                'itemid' => (int) $file->get_itemid(),
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
                'targetformat' => $targetformat,
            ]);
            \core\task\manager::queue_adhoc_task($task);
        }
    }

    /**
     * Decide which format (if any) a given stored file should be converted to,
     * based on whether it is an audio or video recording.
     *
     * The recording's own MIME type cannot be trusted for this: Moodle derives
     * it from the file extension rather than its contents, so an audio-only
     * "audio-*.webm" recording is reported as "video/webm", indistinguishable
     * from an actual video. The upload endpoint always names recordings
     * "audio-..." or "video-..." after the type the user actually chose, so
     * that prefix is used instead.
     *
     * @param \stored_file $file the recording to inspect
     * @param string $audioformat the configured audio target format
     * @param string $videoformat the configured video target format
     * @return string|null the target format, or null if this file should be left alone
     */
    protected static function target_format_for(\stored_file $file, string $audioformat, string $videoformat): ?string {
        $filename = $file->get_filename();

        if (strpos($filename, 'audio-') === 0) {
            return $audioformat !== self::FORMAT_NONE ? $audioformat : null;
        }

        if (strpos($filename, 'video-') === 0) {
            return $videoformat !== self::FORMAT_NONE ? $videoformat : null;
        }

        return null;
    }

    /**
     * Whether a stored file's extension already matches the target format.
     *
     * @param \stored_file $file the file to check
     * @param string $format the target format, e.g. "mp3"
     * @return bool true when no conversion is necessary
     */
    protected static function matches_format(\stored_file $file, string $format): bool {
        return strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === strtolower($format);
    }

    /**
     * Build the filename the transcoded copy of a recording should use: the
     * original name with its extension replaced by the target format.
     *
     * @param string $filename the original filename
     * @param string $format the target format, e.g. "mp3"
     * @return string the filename to give the transcoded copy
     */
    public static function transcoded_filename(string $filename, string $format): string {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        return ($base !== '' ? $base : 'recording') . '.' . $format;
    }

    /**
     * The ffmpeg arguments (other than input/output) used to convert to a given format.
     *
     * @param string $format the target format, e.g. "mp3"
     * @return string the ffmpeg arguments to use, or '' if the format is not supported
     */
    protected static function ffmpeg_arguments(string $format): string {
        switch ($format) {
            case 'mp3':
                return '-vn -acodec libmp3lame -qscale:a 2';
            case 'mp4':
                return '-c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 160k';
            default:
                return '';
        }
    }

    /**
     * Convert a stored recording to the given format with ffmpeg, storing the
     * result alongside the original in the same file area.
     *
     * Any previously transcoded copy with the same target filename is replaced.
     *
     * @param \stored_file $source the recording to convert
     * @param string $targetformat the format to convert to, e.g. "mp3"
     * @return \stored_file|null the stored transcoded copy, or null on failure
     */
    public static function transcode(\stored_file $source, string $targetformat): ?\stored_file {
        if (!self::is_ffmpeg_available()) {
            return null;
        }

        $arguments = self::ffmpeg_arguments($targetformat);
        if ($arguments === '') {
            return null;
        }

        $targetfilename = self::transcoded_filename($source->get_filename(), $targetformat);

        $tmpdir = make_request_directory();
        $sourcepath = $tmpdir . '/source_' . clean_param($source->get_filename(), PARAM_FILE);
        $targetpath = $tmpdir . '/target_' . clean_param($targetfilename, PARAM_FILE);

        $source->copy_content_to($sourcepath);

        $command = implode(' ', [
            escapeshellarg(self::ffmpeg_path()),
            '-y',
            '-i', escapeshellarg($sourcepath),
            $arguments,
            escapeshellarg($targetpath),
        ]);

        $output = [];
        $exitcode = 1;
        @exec($command . ' 2>&1', $output, $exitcode);

        if ($exitcode !== 0 || !is_file($targetpath) || filesize($targetpath) === 0) {
            return null;
        }

        $fs = get_file_storage();
        $existing = $fs->get_file(
            $source->get_contextid(),
            $source->get_component(),
            $source->get_filearea(),
            $source->get_itemid(),
            $source->get_filepath(),
            $targetfilename
        );
        if ($existing) {
            $existing->delete();
        }

        $filerecord = (object) [
            'contextid' => $source->get_contextid(),
            'component' => $source->get_component(),
            'filearea' => $source->get_filearea(),
            'itemid' => $source->get_itemid(),
            'filepath' => $source->get_filepath(),
            'filename' => $targetfilename,
        ];

        return $fs->create_file_from_pathname($filerecord, $targetpath);
    }

    /**
     * Add the transcoded recording to a post's message as a fallback <source>,
     * so that browsers which can play it will offer it alongside the original.
     *
     * The textless forum recorder always embeds recordings as a single
     * "<source src=\"@@PLUGINFILE@@/{filename}\">" element with no "type"
     * attribute (see {@see manager} / the upload endpoint). This looks for that
     * exact element and, if found and not already extended, appends a second
     * "<source>" pointing at the transcoded file with its mimetype set, so the
     * browser can fall back to it.
     *
     * The post is updated directly via the database rather than through
     * forum_update_post(), so as not to trigger another post_updated event and
     * re-queue this same transcode.
     *
     * @param int $postid the id of the forum post to update
     * @param \stored_file $original the original recording referenced by the post
     * @param \stored_file $transcoded the newly transcoded copy of that recording
     * @return void
     */
    public static function add_source_to_message(int $postid, \stored_file $original, \stored_file $transcoded): void {
        global $DB;

        $post = $DB->get_record('forum_posts', ['id' => $postid], 'id, message', IGNORE_MISSING);
        if (!$post) {
            return;
        }

        $originalsource = '<source src="@@PLUGINFILE@@/' . $original->get_filename() . '">';
        if (strpos($post->message, $originalsource) === false) {
            // The message has changed since this recording was queued (e.g. a
            // re-recorded edit replaced it); leave it alone.
            return;
        }

        $newsource = '<source src="@@PLUGINFILE@@/' . $transcoded->get_filename()
            . '" type="' . s($transcoded->get_mimetype()) . '">';
        if (strpos($post->message, $newsource) !== false) {
            // Already added (e.g. the task ran more than once).
            return;
        }

        $message = str_replace($originalsource, $originalsource . $newsource, $post->message);
        if ($message === $post->message) {
            return;
        }

        $DB->set_field('forum_posts', 'message', $message, ['id' => $postid]);
    }
}
