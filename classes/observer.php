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
     * Remove configuration rows orphaned by a course deletion.
     *
     * Course deletion removes the course's forums without firing
     * course_module_deleted for them (remove_course_contents deletes modules
     * directly), so any configuration row whose forum no longer exists is
     * swept here instead.
     *
     * @param \core\event\course_deleted $event the course deletion event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $DB->delete_records_select('local_textless_forum', 'forum NOT IN (SELECT id FROM {forum})');
    }

    /**
     * Keep a new reply to recordings only, and queue its transcoding.
     *
     * @param \mod_forum\event\post_created $event the post creation event
     * @return void
     */
    public static function post_created(\mod_forum\event\post_created $event): void {
        self::handle_post((int) $event->objectid, $event);
    }

    /**
     * Keep an edited post to recordings only, and queue its transcoding.
     *
     * Edits can replace the recording (record again before saving an edit), so
     * the post's files are inspected again on every update.
     *
     * @param \mod_forum\event\post_updated $event the post update event
     * @return void
     */
    public static function post_updated(\mod_forum\event\post_updated $event): void {
        self::handle_post((int) $event->objectid, $event);
    }

    /**
     * Keep the first post of a new discussion to recordings only, and queue
     * its transcoding.
     *
     * mod_forum triggers discussion_created, not post_created, for the post
     * that starts a discussion (mod/forum/post.php, and the add_discussion
     * web service).
     *
     * @param \mod_forum\event\discussion_created $event the discussion creation event
     * @return void
     */
    public static function discussion_created(\mod_forum\event\discussion_created $event): void {
        global $DB;

        $postid = (int) $DB->get_field('forum_discussions', 'firstpost', ['id' => $event->objectid]);
        self::handle_post($postid, $event);
    }

    /**
     * For a post in a textless forum, strip anything but recordings from its
     * message, then queue background transcoding for its recordings.
     *
     * @param int $postid the mod_forum post id
     * @param \core\event\base $event the post or discussion event
     * @return void
     */
    protected static function handle_post(int $postid, \core\event\base $event): void {
        $forumid = (int) ($event->other['forumid'] ?? 0);
        if (!$postid || !$forumid || !manager::is_textless($forumid)) {
            return;
        }

        manager::enforce_recording_only($postid, $forumid);
        transcoder::queue_for_post($postid, (int) $event->contextid);
    }
}
