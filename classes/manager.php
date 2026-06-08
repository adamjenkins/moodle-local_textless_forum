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
 * Reads and writes the per-forum textless configuration.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string Allow audio recordings only. */
    const MODE_AUDIO = 'audio';

    /** @var string Allow video recordings only. */
    const MODE_VIDEO = 'video';

    /** @var string Allow both audio and video recordings. */
    const MODE_BOTH = 'both';

    /** @var string Name of the database table storing the configuration. */
    const TABLE = 'local_textless_forum';

    /** @var int Default maximum length of a single recording, in seconds. */
    const DEFAULT_MAX_DURATION = 120;

    /** @var int Default audio bitrate requested from the browser's recorder, in bits per second. */
    const DEFAULT_AUDIO_BITRATE = 128000;

    /** @var int Default video bitrate requested from the browser's recorder, in bits per second. */
    const DEFAULT_VIDEO_BITRATE = 2500000;

    /**
     * Return the textless configuration for a forum, or null when none is stored.
     *
     * @param int $forumid the mod_forum instance id
     * @return \stdClass|null the configuration record or null
     */
    public static function get_settings(int $forumid): ?\stdClass {
        global $DB;

        if (empty($forumid)) {
            return null;
        }

        $record = $DB->get_record(self::TABLE, ['forum' => $forumid]);

        return $record ?: null;
    }

    /**
     * Whether the given forum is configured as textless (RecordRTC only).
     *
     * @param int $forumid the mod_forum instance id
     * @return bool true when the forum enforces RecordRTC only content
     */
    public static function is_textless(int $forumid): bool {
        $settings = self::get_settings($forumid);

        return !empty($settings) && !empty($settings->enabled);
    }

    /**
     * Return the allowed recording mode for a forum.
     *
     * Defaults to MODE_BOTH when nothing more specific is stored.
     *
     * @param int $forumid the mod_forum instance id
     * @return string one of the MODE_* constants
     */
    public static function get_mode(int $forumid): string {
        $settings = self::get_settings($forumid);

        if (empty($settings) || empty($settings->recordingmode)) {
            return self::MODE_BOTH;
        }

        return $settings->recordingmode;
    }

    /**
     * Return the maximum recording length allowed for a forum, in seconds.
     *
     * Defaults to {@see DEFAULT_MAX_DURATION} when nothing more specific is
     * stored. A value of 0 means recordings are not limited.
     *
     * @param int $forumid the mod_forum instance id
     * @return int the maximum recording length in seconds, or 0 for no limit
     */
    public static function get_max_duration(int $forumid): int {
        $settings = self::get_settings($forumid);

        if (empty($settings) || !isset($settings->maxduration)) {
            return self::DEFAULT_MAX_DURATION;
        }

        return (int) $settings->maxduration;
    }

    /**
     * Return the site-wide audio bitrate to request from the browser's recorder.
     *
     * @return int the audio bitrate in bits per second
     */
    public static function get_audio_bitrate(): int {
        $bitrate = (int) get_config('local_textless_forum', 'audiobitrate');

        return $bitrate > 0 ? $bitrate : self::DEFAULT_AUDIO_BITRATE;
    }

    /**
     * Return the site-wide video bitrate to request from the browser's recorder.
     *
     * @return int the video bitrate in bits per second
     */
    public static function get_video_bitrate(): int {
        $bitrate = (int) get_config('local_textless_forum', 'videobitrate');

        return $bitrate > 0 ? $bitrate : self::DEFAULT_VIDEO_BITRATE;
    }

    /**
     * Whether the "Switch camera" button should be offered while previewing
     * a video recording.
     *
     * @return bool true if the button should be shown
     */
    public static function allow_switch_camera(): bool {
        return (bool) get_config('local_textless_forum', 'allowswitchcamera');
    }

    /**
     * Return the list of selectable recording modes for the settings form.
     *
     * @return array mode value => language string
     */
    public static function get_mode_menu(): array {
        return [
            self::MODE_BOTH => get_string('mode_both', 'local_textless_forum'),
            self::MODE_AUDIO => get_string('mode_audio', 'local_textless_forum'),
            self::MODE_VIDEO => get_string('mode_video', 'local_textless_forum'),
        ];
    }

    /**
     * Create or update the textless configuration for a forum.
     *
     * @param int $forumid the mod_forum instance id
     * @param bool $enabled whether the forum is textless
     * @param string $mode one of the MODE_* constants
     * @param int $maxduration maximum recording length in seconds, or 0 for no limit
     * @return void
     */
    public static function save_settings(
        int $forumid,
        bool $enabled,
        string $mode,
        int $maxduration = self::DEFAULT_MAX_DURATION
    ): void {
        global $DB;

        if (empty($forumid)) {
            return;
        }

        if (!in_array($mode, [self::MODE_AUDIO, self::MODE_VIDEO, self::MODE_BOTH], true)) {
            $mode = self::MODE_BOTH;
        }

        if ($maxduration < 0) {
            $maxduration = self::DEFAULT_MAX_DURATION;
        }

        $now = time();
        $record = $DB->get_record(self::TABLE, ['forum' => $forumid]);

        if ($record) {
            $record->enabled = $enabled ? 1 : 0;
            $record->recordingmode = $mode;
            $record->maxduration = $maxduration;
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record = (object) [
                'forum' => $forumid,
                'enabled' => $enabled ? 1 : 0,
                'recordingmode' => $mode,
                'maxduration' => $maxduration,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /**
     * Remove the stored configuration for a forum.
     *
     * @param int $forumid the mod_forum instance id
     * @return void
     */
    public static function delete_settings(int $forumid): void {
        global $DB;

        if (empty($forumid)) {
            return;
        }

        $DB->delete_records(self::TABLE, ['forum' => $forumid]);
    }
}
