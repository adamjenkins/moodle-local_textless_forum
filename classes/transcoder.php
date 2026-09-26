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
     * Run a shell command and collect its output and exit code.
     *
     * This is the plugin's only process spawn, so that tests can replace it.
     * Callers must go through {@see try_command()}, which also survives a
     * runtime that throws instead of spawning.
     *
     * @param string $command the complete, already escaped command line
     * @return array [string[] $output, int $exitcode]
     */
    protected static function run_command(string $command): array {
        $output = [];
        $exitcode = 1;
        @exec($command . ' 2>&1', $output, $exitcode);

        return [$output, $exitcode];
    }

    /**
     * Run a shell command, treating any failure to spawn it as a failed command.
     *
     * The `@` in {@see run_command()} only silences PHP diagnostics; a SAPI
     * that cannot spawn processes may throw instead, even though exec() exists.
     * An exec() removed by disable_functions throws \Error ("Call to undefined
     * function") since PHP 8, so this catch covers that host too.
     *
     * @param string $command the complete, already escaped command line
     * @return array [string[] $output, int $exitcode], with exit code -1 when the command could not be run
     */
    protected static function try_command(string $command): array {
        try {
            return static::run_command($command);
        } catch (\Throwable $e) {
            return [[], -1];
        }
    }

    /**
     * Whether the configured ffmpeg command can actually be run on this server.
     *
     * This spawns a process, so it must only be called where one is expected
     * (the background tasks) — never while rendering a page, because some
     * runtimes (PHP-WASM) never return from a spawn attempt. Pages read the
     * stored result via {@see get_ffmpeg_status()} instead.
     *
     * The result is cached for the lifetime of the request, and stored by
     * {@see check_ffmpeg()}.
     *
     * @return bool true when "<ffmpeg> -version" runs successfully
     */
    public static function is_ffmpeg_available(): bool {
        if (self::$ffmpegavailable !== null) {
            return self::$ffmpegavailable;
        }

        return static::check_ffmpeg();
    }

    /**
     * Probe the configured ffmpeg command now and store the result.
     *
     * When ffmpeg cannot be run, transcoding is switched off, so the settings
     * page does not offer a control that cannot work.
     *
     * @return bool true when "<ffmpeg> -version" runs successfully
     */
    public static function check_ffmpeg(): bool {
        $path = self::ffmpeg_path();
        [, $exitcode] = static::try_command(escapeshellarg($path) . ' -version');
        $available = ($exitcode === 0);

        set_config('ffmpegcheckedpath', $path, 'local_textless_forum');
        set_config('ffmpegavailable', $available ? 1 : 0, 'local_textless_forum');
        if (!$available) {
            set_config('transcodeenabled', 0, 'local_textless_forum');
        }

        return self::$ffmpegavailable = $available;
    }

    /**
     * The stored result of the last ffmpeg check, without spawning anything.
     *
     * @return bool|null true/false from the last check of the currently
     *     configured path, or null when that path has not been checked yet
     */
    public static function get_ffmpeg_status(): ?bool {
        // Unset (false) never matches a path, so it also reads as "not checked".
        if (get_config('local_textless_forum', 'ffmpegcheckedpath') !== self::ffmpeg_path()) {
            return null;
        }

        return !empty(get_config('local_textless_forum', 'ffmpegavailable'));
    }

    /**
     * Queue a background re-check of ffmpeg, e.g. after its settings change.
     *
     * Used as an admin setting "updated" callback, so it must not spawn.
     *
     * @return void
     */
    public static function queue_ffmpeg_check(): void {
        \core\task\manager::queue_adhoc_task(new \local_textless_forum\task\check_ffmpeg(), true);
    }

    /**
     * Whether transcoding should actually happen: the administrator has turned
     * it on, and ffmpeg is not known to be missing.
     *
     * This reads the stored check result and never spawns, since it runs when
     * a post is saved. A path that has not been checked yet counts as usable:
     * the transcoding task checks it before running it, and switches
     * transcoding off if it is missing.
     *
     * @return bool true when recordings should be transcoded
     */
    public static function is_enabled(): bool {
        return !empty(get_config('local_textless_forum', 'transcodeenabled'))
            && self::get_ffmpeg_status() !== false;
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

        [, $exitcode] = static::try_command($command);

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
