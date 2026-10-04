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
 * Tests for the upload endpoint's checks (upload.php hands every upload to recording::store()).
 *
 * The MP4 video fixture is Moodle core's own test media
 * (lib/editor/tiny/tests/behat/fixtures/moodle-logo.mp4); the WebM video
 * fixture is a short VP8 WebM file. Audio-only recordings are built in the
 * test, since no media tool is available to make real ones.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_textless_forum\recording
 */
final class recording_test extends \advanced_testcase {
    /**
     * Write bytes to a new temporary file.
     *
     * @param string $content the file content
     * @return string the file path
     */
    protected function make_file(string $content): string {
        $path = make_request_directory() . '/upload';
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * An audio-only WebM recording: EBML header, an Opus track, then a Cluster.
     *
     * The cluster payload contains "V_VP8" on purpose: media data must not be
     * mistaken for a video track header.
     *
     * @param int $size the total size in bytes
     * @return string the file path
     */
    protected function audio_webm(int $size = 2000): string {
        $header = "\x1A\x45\xDF\xA3\x42\x82\x84webm" . str_repeat("\x00", 16) . "\x86\x86A_OPUS\x83\x81\x02";
        $cluster = "\x1F\x43\xB6\x75" . 'V_VP8';

        return $this->make_file(str_pad($header . $cluster, $size, "\x55"));
    }

    /**
     * An audio-only MP4 recording: ftyp, then a moov with a sound handler.
     *
     * @return string the file path
     */
    protected function audio_mp4(): string {
        return $this->make_file("\x00\x00\x00\x18ftypisom" . str_repeat("\x00", 12) . 'moov'
            . 'hdlr' . str_repeat("\x00", 8) . 'soun' . str_repeat("\x00", 100) . 'mdat' . str_repeat("\x55", 500));
    }

    /**
     * An audio-only Ogg (Opus) recording.
     *
     * @return string the file path
     */
    protected function audio_ogg(): string {
        return $this->make_file('OggS' . str_repeat("\x00", 24) . 'OpusHead' . str_repeat("\x55", 500));
    }

    /**
     * Create a course, a forum with the given textless settings, and an enrolled student.
     *
     * @param bool $enabled whether the forum is textless
     * @param string $mode the recording mode
     * @param int $maxduration the maximum recording length in seconds
     * @param array $forumrecord extra forum fields
     * @return array [\context_module $context, \stdClass $student, \stdClass $course]
     */
    protected function make_forum(
        bool $enabled = true,
        string $mode = manager::MODE_BOTH,
        int $maxduration = 120,
        array $forumrecord = []
    ): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id] + $forumrecord);
        manager::save_settings($forum->id, $enabled, $mode, $maxduration);
        $student = $generator->create_and_enrol($course, 'student');

        return [\context_module::instance($forum->cmid), $student, $course];
    }

    /**
     * Assert that storing a recording is refused with the given error code.
     *
     * @param string $errorcode the expected moodle_exception error code
     * @param callable $store the call that must be refused
     * @return void
     */
    protected function assert_refused(string $errorcode, callable $store): void {
        try {
            $store();
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $e->getMessage());
            $this->assertEmpty(
                get_file_storage()->get_area_files(
                    \context_user::instance($GLOBALS['USER']->id)->id,
                    'user',
                    'draft',
                    7,
                    '',
                    false
                ),
                'Nothing may be stored when the upload is refused'
            );
            return;
        }
        $this->fail("Expected a '$errorcode' refusal, but the recording was stored");
    }

    /**
     * Containers are recognised from the content, not the name.
     *
     * @return void
     */
    public function test_detect_container(): void {
        $this->resetAfterTest();

        $this->assertSame(recording::CONTAINER_MATROSKA, recording::detect_container(__DIR__ . '/fixtures/video.webm'));
        $this->assertSame(recording::CONTAINER_MP4, recording::detect_container(__DIR__ . '/fixtures/video.mp4'));
        $this->assertSame(recording::CONTAINER_MATROSKA, recording::detect_container($this->audio_webm()));
        $this->assertSame(recording::CONTAINER_MP4, recording::detect_container($this->audio_mp4()));
        $this->assertSame(recording::CONTAINER_OGG, recording::detect_container($this->audio_ogg()));
        $this->assertNull(recording::detect_container($this->make_file('<html><script>alert(1)</script></html>')));
        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\nhttp://example.invalid/a.ts\n";
        $this->assertNull(recording::detect_container($this->make_file($playlist)));
        $this->assertNull(recording::detect_container($this->make_file('')));
    }

    /**
     * Video tracks are found in real video files and not in audio-only ones.
     *
     * @return void
     */
    public function test_has_video_track(): void {
        $this->resetAfterTest();

        $this->assertTrue(recording::has_video_track(__DIR__ . '/fixtures/video.webm', recording::CONTAINER_MATROSKA));
        $this->assertTrue(recording::has_video_track(__DIR__ . '/fixtures/video.mp4', recording::CONTAINER_MP4));
        $this->assertFalse(recording::has_video_track($this->audio_webm(), recording::CONTAINER_MATROSKA));
        $this->assertFalse(recording::has_video_track($this->audio_mp4(), recording::CONTAINER_MP4));
        $this->assertFalse(recording::has_video_track($this->audio_ogg(), recording::CONTAINER_OGG));
    }

    /**
     * A valid audio recording is stored in the user's draft area under a server-chosen name.
     *
     * @return void
     */
    public function test_store_audio(): void {
        global $USER;
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(true, manager::MODE_AUDIO);
        $this->setUser($student);

        $file = recording::store($context, 7, 'audio', $this->audio_webm());

        $this->assertSame(\context_user::instance($USER->id)->id, (int) $file->get_contextid());
        $this->assertSame('user', $file->get_component());
        $this->assertSame('draft', $file->get_filearea());
        $this->assertSame(7, (int) $file->get_itemid());
        $this->assertMatchesRegularExpression('~^audio-\d+-\w{6}\.webm$~', $file->get_filename());
        $this->assertEquals(2000, $file->get_filesize());
    }

    /**
     * A valid video recording is stored, and an MP4 one keeps its container's extension.
     *
     * @return void
     */
    public function test_store_video(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(true, manager::MODE_VIDEO);
        $this->setUser($student);

        $file = recording::store($context, 7, 'video', __DIR__ . '/fixtures/video.mp4');

        $this->assertMatchesRegularExpression('~^video-\d+-\w{6}\.mp4$~', $file->get_filename());
    }

    /**
     * A forum that is not textless accepts no uploads.
     *
     * @return void
     */
    public function test_refused_when_not_textless(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(false);
        $this->setUser($student);

        $this->assert_refused('nottextless', fn() => recording::store($context, 7, 'audio', $this->audio_webm()));
    }

    /**
     * Without the reply or start-discussion capability, nothing can be uploaded.
     *
     * @return void
     */
    public function test_refused_without_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/forum:replypost', CAP_PROHIBIT, $roleid, $context->id, true);
        assign_capability('mod/forum:startdiscussion', CAP_PROHIBIT, $roleid, $context->id, true);
        $this->setUser($student);

        $this->assert_refused('nopermissions', fn() => recording::store($context, 7, 'audio', $this->audio_webm()));
    }

    /**
     * A user who is not enrolled in the course cannot upload.
     *
     * @return void
     */
    public function test_refused_when_not_enrolled(): void {
        $this->resetAfterTest();
        [$context] = $this->make_forum();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        recording::store($context, 7, 'audio', $this->audio_webm());
    }

    /**
     * Only a forum module context is accepted.
     *
     * @return void
     */
    public function test_refused_for_other_context(): void {
        $this->resetAfterTest();
        [, $student, $course] = $this->make_forum();
        $this->setUser($student);

        $this->assert_refused(
            'invalidcontext',
            fn() => recording::store(\context_course::instance($course->id), 7, 'audio', $this->audio_webm())
        );
    }

    /**
     * The declared type must be allowed by the forum's mode.
     *
     * @return void
     */
    public function test_refused_declared_type_not_allowed(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(true, manager::MODE_AUDIO);
        $this->setUser($student);

        $video = __DIR__ . '/fixtures/video.webm';
        $this->assert_refused('recordingnotallowed', fn() => recording::store($context, 7, 'video', $video));
        $this->assert_refused('recordingnotallowed', fn() => recording::store($context, 7, 'none', $this->audio_webm()));
    }

    /**
     * A video declared as audio is refused in an audio-only forum: the content decides.
     *
     * @return void
     */
    public function test_refused_video_declared_as_audio(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(true, manager::MODE_AUDIO);
        $this->setUser($student);

        $webm = __DIR__ . '/fixtures/video.webm';
        $mp4 = __DIR__ . '/fixtures/video.mp4';
        $this->assert_refused('recordingnotallowed', fn() => recording::store($context, 7, 'audio', $webm));
        $this->assert_refused('recordingnotallowed', fn() => recording::store($context, 7, 'audio', $mp4));
    }

    /**
     * An audio file declared as video is refused in a video-only forum.
     *
     * @return void
     */
    public function test_refused_audio_declared_as_video(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(true, manager::MODE_VIDEO);
        $this->setUser($student);

        $this->assert_refused('recordingnotallowed', fn() => recording::store($context, 7, 'video', $this->audio_webm()));
    }

    /**
     * Anything that is not a recording container is refused, whatever its name.
     *
     * @return void
     */
    public function test_refused_not_media(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum();
        $this->setUser($student);

        $this->assert_refused(
            'recordingnotmedia',
            fn() => recording::store($context, 7, 'audio', $this->make_file('<html><script>alert(1)</script></html>'))
        );
        $this->assert_refused(
            'recordingnotmedia',
            fn() => recording::store($context, 7, 'audio', $this->make_file("ffconcat version 1.0\nfile '/etc/passwd'\n"))
        );
    }

    /**
     * The forum's upload size limit applies.
     *
     * @return void
     */
    public function test_refused_over_maxbytes(): void {
        $this->resetAfterTest();
        [$context, $student] = $this->make_forum(true, manager::MODE_BOTH, 0, ['maxbytes' => 1000]);
        $this->setUser($student);

        $this->assert_refused('maxbytesfile', fn() => recording::store($context, 7, 'audio', $this->audio_webm(2000)));
    }

    /**
     * A recording bigger than the maximum length allows is refused.
     *
     * @return void
     */
    public function test_refused_over_duration_budget(): void {
        $this->resetAfterTest();
        $size = recording::max_bytes_for_duration(1, manager::MODE_AUDIO) + 1;
        [$context, $student] = $this->make_forum(true, manager::MODE_BOTH, 1);
        $this->setUser($student);

        $this->assert_refused('recordingtoolong', fn() => recording::store($context, 7, 'audio', $this->audio_webm($size)));
    }

    /**
     * With no maximum length, the same recording is stored.
     *
     * @return void
     */
    public function test_no_duration_budget_without_limit(): void {
        $this->resetAfterTest();
        $size = recording::max_bytes_for_duration(1, manager::MODE_AUDIO) + 1;
        [$context, $student] = $this->make_forum(true, manager::MODE_BOTH, 0);
        $this->setUser($student);

        $this->assertEquals($size, recording::store($context, 7, 'audio', $this->audio_webm($size))->get_filesize());
    }

    /**
     * The length budget follows the maximum length and the recorder's bitrate.
     *
     * @return void
     */
    public function test_max_bytes_for_duration(): void {
        $this->resetAfterTest();

        $this->assertSame(0, recording::max_bytes_for_duration(0, manager::MODE_AUDIO));
        // 60 s of 128 kb/s audio is 960 000 bytes; twice that plus the overhead.
        $this->assertSame(1920000 + recording::SIZE_OVERHEAD, recording::max_bytes_for_duration(60, manager::MODE_AUDIO));
        $this->assertSame(
            (int) (60 * (128000 + 2500000) / 8 * 2) + recording::SIZE_OVERHEAD,
            recording::max_bytes_for_duration(60, manager::MODE_VIDEO)
        );

        // A higher configured bitrate raises the budget; a lower one never lowers it.
        set_config('audiobitrate', 320000, 'local_textless_forum');
        $this->assertSame(4800000 + recording::SIZE_OVERHEAD, recording::max_bytes_for_duration(60, manager::MODE_AUDIO));
        set_config('audiobitrate', 24000, 'local_textless_forum');
        $this->assertSame(1920000 + recording::SIZE_OVERHEAD, recording::max_bytes_for_duration(60, manager::MODE_AUDIO));
    }
}
