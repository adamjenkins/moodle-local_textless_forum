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
 * @covers     \local_textless_forum\recording
 */
final class observer_test extends \advanced_testcase {
    /** @var string An audio recording as the recorder embeds it, after mod_forum saved it. */
    const AUDIO = '<audio controls="true"><source src="@@PLUGINFILE@@/audio-1759000000-abcdef.webm"></audio>';

    /** @var string A video recording as the recorder embeds it, after mod_forum saved it. */
    const VIDEO = '<video controls="true"><source src="@@PLUGINFILE@@/video-1759000000-abcdef.webm"></video>';

    /**
     * The bytes of an audio-only WebM recording: EBML header, an Opus track, then a Cluster.
     *
     * @param int $size the total size in bytes
     * @return string the file content
     */
    protected function audio_webm(int $size = 2000): string {
        $header = "\x1A\x45\xDF\xA3\x42\x82\x84webm" . str_repeat("\x00", 16) . "\x86\x86A_OPUS\x83\x81\x02";

        return str_pad($header . "\x1F\x43\xB6\x75", $size, "\x55");
    }

    /**
     * The bytes of a short VP8 WebM video recording.
     *
     * @return string the file content
     */
    protected function video_webm(): string {
        return file_get_contents(__DIR__ . '/fixtures/video.webm');
    }

    /**
     * Store a file in a post's file area, as mod_forum does when the post is saved.
     *
     * @param \context_module $context the forum context
     * @param int $postid the post id
     * @param string $filename the file name
     * @param string $content the file content
     * @param int|null $userid the uploading user, or null for a file the server created
     * @return \stored_file the stored file
     */
    protected function add_post_file(
        \context_module $context,
        int $postid,
        string $filename,
        string $content,
        ?int $userid
    ): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_forum',
            'filearea' => 'post',
            'itemid' => $postid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $content);
    }

    /**
     * Create a forum with textless settings and a discussion whose first post has the given message.
     *
     * The first post's file area holds the recordings {@see AUDIO} and {@see VIDEO} point at.
     *
     * @param bool $enabled whether the forum is textless
     * @param string $mode the recording mode
     * @param string $message the first post's message
     * @param int $maxduration the forum's maximum recording length in seconds
     * @return array [\stdClass $forum, \stdClass $discussion, \context_module $context]
     */
    protected function make_discussion(
        bool $enabled,
        string $mode,
        string $message,
        int $maxduration = manager::DEFAULT_MAX_DURATION
    ): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_and_enrol($course, 'student');
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        manager::save_settings($forum->id, $enabled, $mode, $maxduration);
        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'message' => $message,
        ]);
        $context = \context_module::instance($forum->cmid);
        $this->add_post_file($context, $discussion->firstpost, 'audio-1759000000-abcdef.webm', $this->audio_webm(), $user->id);
        $this->add_post_file($context, $discussion->firstpost, 'video-1759000000-abcdef.webm', $this->video_webm(), $user->id);

        return [$forum, $discussion, $context];
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
        $this->add_post_file($context, $discussion->firstpost, 'audio-1.webm', $this->audio_webm(), (int) $discussion->userid);
        // The transcoding task's copy: created by the server, so it has no owning user.
        $this->add_post_file($context, $discussion->firstpost, 'audio-1.mp3', 'ID3' . str_repeat("\x55", 100), null);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);

        $this->trigger_post_event(\mod_forum\event\post_updated::class, $forum, $post, $context);

        $this->assertSame($message, $this->post($post->id)->message);
        $this->assertSame($message, manager::restrict_message($message, manager::MODE_BOTH));
    }

    /**
     * A file placed through core's draft upload instead of the recorder is checked
     * when the post is saved: only real recordings of the embedded type stay.
     *
     * @return void
     */
    public function test_draft_uploaded_non_recordings_are_dropped(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_BOTH, self::AUDIO);
        $student = $DB->get_record('user', ['id' => $discussion->userid]);
        $reply = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post([
            'discussion' => $discussion->id,
            'parent' => $discussion->firstpost,
            'userid' => $student->id,
            'message' => '',
        ]);

        // The student uploads files straight into a draft area, as core's
        // repository upload allows, then saves the post with them embedded.
        $this->setUser($student);
        $usercontext = \context_user::instance($student->id);
        $draftitemid = file_get_unused_draft_itemid();
        $draft = [
            'audio-1759000001-aaaaaa.webm' => $this->audio_webm(),
            'audio-1759000002-bbbbbb.webm' => '%PDF-1.4 not a recording' . str_repeat('x', 100),
            'notes.pdf' => '%PDF-1.4 not a recording' . str_repeat('x', 100),
            'audio-1759000003-cccccc.webm' => $this->video_webm(),
        ];
        foreach ($draft as $filename => $content) {
            get_file_storage()->create_file_from_string([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
                'userid' => $student->id,
            ], $content);
        }
        $draftbase = $CFG->wwwroot . '/draftfile.php/' . $usercontext->id . '/user/draft/' . $draftitemid . '/';
        $text = '';
        foreach (array_keys($draft) as $filename) {
            $text .= '<audio controls="true"><source src="' . $draftbase . $filename . '"></audio>';
        }
        $text = file_save_draft_area_files($draftitemid, $context->id, 'mod_forum', 'post', $reply->id, [
            'subdirs' => 0,
            'maxbytes' => 0,
            'maxfiles' => -1,
        ], $text);
        $DB->set_field('forum_posts', 'message', $text, ['id' => $reply->id]);
        $this->assertStringContainsString('@@PLUGINFILE@@/notes.pdf', $text);

        $this->trigger_post_event(\mod_forum\event\post_created::class, $forum, $reply, $context);

        // Only the real audio recording stays: not a non-media file under a
        // recording's name, not any other file, and not a video embedded as audio.
        $this->assertSame(
            '<audio controls="true"><source src="@@PLUGINFILE@@/audio-1759000001-aaaaaa.webm"></audio>',
            $this->post($reply->id)->message
        );
    }

    /**
     * A recording larger than the forum's maximum length allows is dropped at save.
     *
     * @return void
     */
    public function test_over_length_recording_is_dropped(): void {
        $this->resetAfterTest();
        $long = '<audio controls="true"><source src="@@PLUGINFILE@@/audio-2.webm"></audio>';
        // One second allows recording::max_bytes_for_duration(1, audio) bytes; this file is larger.
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_BOTH, self::AUDIO . $long, 1);
        $content = $this->audio_webm(recording::max_bytes_for_duration(1, manager::MODE_AUDIO) + 1);
        $this->add_post_file($context, $discussion->firstpost, 'audio-2.webm', $content, (int) $discussion->userid);

        $this->trigger_discussion_created($forum, $discussion, $context);

        $this->assertSame(self::AUDIO, $this->post($discussion->firstpost)->message);
    }

    /**
     * A poster cannot supply a transcoded fallback: a file named like one but
     * uploaded by a user is dropped, while the recording itself stays.
     *
     * @return void
     */
    public function test_user_supplied_fallback_is_dropped(): void {
        $this->resetAfterTest();
        $message = '<audio controls="true"><source src="@@PLUGINFILE@@/audio-1.webm">'
            . '<source src="@@PLUGINFILE@@/audio-1.mp3" type="audio/mpeg"></audio>';
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_BOTH, $message);
        $this->add_post_file($context, $discussion->firstpost, 'audio-1.webm', $this->audio_webm(), (int) $discussion->userid);
        $this->add_post_file($context, $discussion->firstpost, 'audio-1.mp3', 'ID3 a two-hour mp3', (int) $discussion->userid);

        $this->trigger_discussion_created($forum, $discussion, $context);

        $this->assertSame(
            '<audio controls="true"><source src="@@PLUGINFILE@@/audio-1.webm"></audio>',
            $this->post($discussion->firstpost)->message
        );
    }

    /**
     * A source naming a file that is not in the post's own file area is dropped.
     *
     * @return void
     */
    public function test_missing_file_is_dropped(): void {
        $this->resetAfterTest();
        $missing = '<audio controls="true"><source src="@@PLUGINFILE@@/audio-9.webm"></audio>';
        [$forum, $discussion, $context] = $this->make_discussion(true, manager::MODE_BOTH, $missing . self::VIDEO);

        $this->trigger_discussion_created($forum, $discussion, $context);

        $this->assertSame(self::VIDEO, $this->post($discussion->firstpost)->message);
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
