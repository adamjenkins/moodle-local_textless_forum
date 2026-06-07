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
 * Event observers.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Remove our stored configuration when the related forum is deleted.
     *
     * @param \core\event\course_module_deleted $event the module deletion event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        $modulename = $event->other['modulename'] ?? '';
        if ($modulename !== 'forum') {
            return;
        }

        $forumid = (int) ($event->other['instanceid'] ?? 0);
        if ($forumid) {
            manager::delete_settings($forumid);
        }
    }

    /**
     * Queue background transcoding for a newly created post's recording.
     *
     * @param \mod_forum\event\post_created $event the post creation event
     * @return void
     */
    public static function post_created(\mod_forum\event\post_created $event): void {
        self::queue_transcode($event);
    }

    /**
     * Queue background transcoding for a recording attached to an edited post.
     *
     * Edits can replace the recording (record again before saving an edit), so
     * the post's files are inspected again on every update.
     *
     * @param \mod_forum\event\post_updated $event the post update event
     * @return void
     */
    public static function post_updated(\mod_forum\event\post_updated $event): void {
        self::queue_transcode($event);
    }

    /**
     * Queue background transcoding for the recording attached to a forum post,
     * when that post belongs to a textless forum.
     *
     * @param \core\event\base $event the post creation or update event
     * @return void
     */
    protected static function queue_transcode(\core\event\base $event): void {
        $forumid = (int) ($event->other['forumid'] ?? 0);
        if (!$forumid || !manager::is_textless($forumid)) {
            return;
        }

        transcoder::queue_for_post((int) $event->objectid, (int) $event->contextid);
    }
}
