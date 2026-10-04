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
 * Tests for the event observers: server-side recordings-only posts, and clean-up.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_textless_forum\observer
 * @covers     \local_textless_forum\manager
 */
final class observer_test extends \advanced_testcase {
    /** @var string An audio recording as the recorder embeds it, after mod_forum saved it. */
    const AUDIO = '<audio controls="true"><source src="@@PLUGINFILE@@/audio-1759000000-abcdef.webm"></audio>';

    /** @var string A video recording as the recorder embeds it, after mod_forum saved it. */
    const VIDEO = '<video controls="true"><source src="@@PLUGINFILE@@/video-1759000000-abcdef.webm"></video>';

    /**
     * Create a forum with textless settings and a discussion whose first post has the given message.
     *
     * @param bool $enabled whether the forum is textless
     * @param string $mode the recording mode
     * @param string $message the first post's message
     * @return array [\stdClass $forum, \stdClass $discussion, \context_module $context]
     */
    protected function make_discussion(bool $enabled, string $mode, string $message): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_and_enrol($course, 'student');
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        manager::save_settings($forum->id, $enabled, $mode);
        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'message' => $message,
        ]);

        return [$forum, $discussion, \context_module::instance($forum->cmid)];
    }

    /**
     * Trigger discussion_created as mod_forum does when a discussion is started.
     *
     * @param \stdClass $forum the forum
     * @param \stdClass $discussion the discussion
     * @param \context_module $context the forum context
     * @return void
     */
    protected function trigger_discussion_created(\stdClass $forum, \stdClass $discussion, \context_module $context): void {
        \mod_forum\event\discussion_created::create([
            'context' => $context,
            'objectid' => $discussion->id,
            'other' => ['forumid' => $forum->id],
        ])->trigger();
    }

    /**
     * Trigger post_created or post_updated as mod_forum does.
     *
     * @param string $eventclass the event class
     * @param \stdClass $forum the forum
     * @param \stdClass $post the post
     * @param \context_module $context the forum context
     * @return void
     */
    protected function trigger_post_event(string $eventclass, \stdClass $forum, \stdClass $post, \context_module $context): void {
        $eventclass::create([
            'context' => $context,
            'objectid' => $post->id,
            'other' => ['discussionid' => $post->discussion, 'forumid' => $forum->id, 'forumtype' => $forum->type],
        ])->trigger();
    }

    /**
     * Read a post's message and format back.
     *
     * @param int $postid the post id
     * @return \stdClass the message and messageformat
     */
    protected function post(int $postid): \stdClass {
        global $DB;

        return $DB->get_record('forum_posts', ['id' => $postid], 'message, messageformat', MUST_EXIST);
    }

    /**
     * Typed text in a new discussion is removed; the recording stays.
     *
     * @return void
     */
    public function test_discussion_created_strips_text(): void {
        $this->resetAfterTest();
        [$forum, $discussion, $context] = $this->make_discussion(
            true,
            manager::MODE_BOTH,
            '<p>I typed this instead</p>' . self::AUDIO . '<img src="x" onerror="alert(1)">'
        );

        $this->trigger_discussion_created($forum, $discussion, $context);

        $post = $this->post($discussion->firstpost);
        $this->assertSame(self::AUDIO, $post->message);
        $this->assertEquals(FORMAT_HTML, $post->messageformat);
    }

    /**
     * A text-only post in a textless forum ends up with an empty message.
     *
     * @return void
     */
    public function test_post_created_text_only_is_emptied(): void {
        global $DB;
        $this->resetAfterTest();
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_BOTH, self::AUDIO);
        $reply = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $discussion->userid,
            'message' => 'Just text, sent without the recorder',
            'messageformat' => FORMAT_MARKDOWN,
        ]);

        $this->trigger_post_event(\mod_forum\event\post_created::class, $forum, $reply, $context);

        $post = $this->post($reply->id);
        $this->assertSame('', $post->message);
        $this->assertEquals(FORMAT_HTML, $post->messageformat);
        // The first post was not touched.
        $this->assertSame(self::AUDIO, $DB->get_field('forum_posts', 'message', ['id' => $discussion->firstpost]));
    }

    /**
     * An edit is checked too, and the forum's mode decides which recordings stay.
     *
     * @return void
     */
    public function test_post_updated_keeps_allowed_recordings_only(): void {
        global $DB;
        $this->resetAfterTest();
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_AUDIO, self::AUDIO);
        $DB->set_field('forum_posts', 'message', 'Edited: ' . self::VIDEO . self::AUDIO, ['id' => $discussion->firstpost]);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);

        $this->trigger_post_event(\mod_forum\event\post_updated::class, $forum, $post, $context);

        $this->assertSame(self::AUDIO, $this->post($post->id)->message);
    }

    /**
     * A recorder post, including the transcoded fallback source, is left exactly as it is.
     *
     * @return void
     */
    public function test_recorder_post_is_unchanged(): void {
        global $DB;
        $this->resetAfterTest();
        $message = '<audio controls="true"><source src="@@PLUGINFILE@@/audio-1.webm">'
            . '<source src="@@PLUGINFILE@@/audio-1.mp3" type="audio/mpeg"></audio>' . self::VIDEO;
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_BOTH, $message);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);

        $this->trigger_post_event(\mod_forum\event\post_updated::class, $forum, $post, $context);

        $this->assertSame($message, $this->post($post->id)->message);
        $this->assertSame($message, manager::restrict_message($message, manager::MODE_BOTH));
    }

    /**
     * Sources that do not point at the post's own files, and extra attributes, are dropped.
     *
     * @return void
     */
    public function test_restrict_message_rebuilds_elements(): void {
        $this->resetAfterTest();

        $this->assertSame('', manager::restrict_message(
            '<audio controls="true"><source src="https://example.invalid/a.webm"></audio>',
            manager::MODE_BOTH
        ));
        $this->assertSame(self::AUDIO, manager::restrict_message(
            '<AUDIO controls onplay="alert(1)"><source src="@@PLUGINFILE@@/audio-1759000000-abcdef.webm" onerror="x"></AUDIO>',
            manager::MODE_BOTH
        ));
        $this->assertSame(self::VIDEO, manager::restrict_message(self::AUDIO . self::VIDEO, manager::MODE_VIDEO));
    }

    /**
     * Posts in a forum that is not textless are never changed.
     *
     * @return void
     */
    public function test_not_textless_forum_is_untouched(): void {
        $this->resetAfterTest();
        [$forum, $discussion, $context] = $this->make_discussion(false, manager::MODE_BOTH, '<p>Ordinary text</p>');

        $this->trigger_discussion_created($forum, $discussion, $context);

        $this->assertSame('<p>Ordinary text</p>', $this->post($discussion->firstpost)->message);
    }

    /**
     * Deleting the forum removes its configuration, and no other forum's.
     *
     * @return void
     */
    public function test_course_module_deleted_removes_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $other = $generator->create_module('forum', ['course' => $course->id]);
        manager::save_settings($forum->id, true, manager::MODE_AUDIO);
        manager::save_settings($other->id, true, manager::MODE_AUDIO);

        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($course->id)->delete($forum->cmid);
        } else {
            course_delete_module($forum->cmid);
        }

        $this->assertFalse($DB->record_exists(manager::TABLE, ['forum' => $forum->id]));
        $this->assertTrue($DB->record_exists(manager::TABLE, ['forum' => $other->id]));
    }

    /**
     * Deleting a course sweeps the configuration of its forums, and keeps other courses'.
     *
     * @return void
     */
    public function test_course_deleted_sweeps_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $othercourse = $generator->create_course();
        $other = $generator->create_module('forum', ['course' => $othercourse->id]);
        manager::save_settings($forum->id, true, manager::MODE_AUDIO);
        manager::save_settings($other->id, true, manager::MODE_AUDIO);

        delete_course($course, false);

        $this->assertFalse($DB->record_exists(manager::TABLE, ['forum' => $forum->id]));
        $this->assertTrue($DB->record_exists(manager::TABLE, ['forum' => $other->id]));
    }
}
