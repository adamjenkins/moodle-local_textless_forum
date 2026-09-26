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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/testable_transcoder.php');

/**
 * Tests for the ffmpeg check: it must survive a runtime that cannot spawn,
 * and must never run while a page (the admin tree) is being built.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_textless_forum\transcoder
 */
final class transcoder_test extends \advanced_testcase {
    /**
     * Start every test with no cached or stored ffmpeg result.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        testable_transcoder::reset();
    }

    /**
     * Leave no scripted state behind for other tests.
     *
     * @return void
     */
    protected function tearDown(): void {
        testable_transcoder::reset();
        parent::tearDown();
    }

    /**
     * A spawn that throws counts as ffmpeg being unavailable, and nothing escapes.
     *
     * @return void
     */
    public function test_spawn_that_throws_means_unavailable(): void {
        set_config('transcodeenabled', 1, 'local_textless_forum');
        testable_transcoder::$result = new \RuntimeException('popen(), proc_open() etc. are unsupported');

        $this->assertFalse(testable_transcoder::is_ffmpeg_available());

        $this->assertCount(1, testable_transcoder::$commands);
        $this->assertFalse(transcoder::get_ffmpeg_status());
        $this->assertEquals(0, get_config('local_textless_forum', 'transcodeenabled'));
        $this->assertFalse(transcoder::is_enabled());
    }

    /**
     * An exec() removed by disable_functions (an \Error in PHP 8) counts as unavailable.
     *
     * @return void
     */
    public function test_disabled_exec_means_unavailable(): void {
        testable_transcoder::$result = new \Error('Call to undefined function exec()');

        $this->assertFalse(testable_transcoder::is_ffmpeg_available());
        $this->assertFalse(transcoder::get_ffmpeg_status());
    }

    /**
     * A command that runs and exits 0 means ffmpeg is available, and is stored.
     *
     * @return void
     */
    public function test_successful_command_means_available(): void {
        set_config('ffmpegpath', '/opt/ffmpeg/bin/ffmpeg', 'local_textless_forum');
        set_config('transcodeenabled', 1, 'local_textless_forum');
        testable_transcoder::$result = [['ffmpeg version 6.1'], 0];

        $this->assertTrue(testable_transcoder::is_ffmpeg_available());

        $this->assertSame(["'/opt/ffmpeg/bin/ffmpeg' -version"], testable_transcoder::$commands);
        $this->assertTrue(transcoder::get_ffmpeg_status());
        $this->assertTrue(transcoder::is_enabled());

        // Cached for the request: no second spawn.
        $this->assertTrue(testable_transcoder::is_ffmpeg_available());
        $this->assertCount(1, testable_transcoder::$commands);
    }

    /**
     * A command that runs but fails means ffmpeg is unavailable.
     *
     * @return void
     */
    public function test_failing_command_means_unavailable(): void {
        testable_transcoder::$result = [['sh: 1: ffmpeg: not found'], 127];

        $this->assertFalse(testable_transcoder::is_ffmpeg_available());
        $this->assertFalse(transcoder::get_ffmpeg_status());
    }

    /**
     * A stored result only applies to the path that was checked.
     *
     * @return void
     */
    public function test_status_is_unknown_for_an_unchecked_path(): void {
        $this->assertNull(transcoder::get_ffmpeg_status());

        testable_transcoder::check_ffmpeg();
        $this->assertTrue(transcoder::get_ffmpeg_status());

        set_config('ffmpegpath', '/somewhere/else/ffmpeg', 'local_textless_forum');
        $this->assertNull(transcoder::get_ffmpeg_status());
    }

    /**
     * Deciding whether to transcode (done when a post is saved) never spawns.
     *
     * @return void
     */
    public function test_is_enabled_does_not_spawn(): void {
        $this->assertFalse(testable_transcoder::is_enabled());

        set_config('transcodeenabled', 1, 'local_textless_forum');
        $this->assertTrue(testable_transcoder::is_enabled(), 'An unchecked path is left to the task to check');

        set_config('ffmpegcheckedpath', transcoder::ffmpeg_path(), 'local_textless_forum');
        set_config('ffmpegavailable', 0, 'local_textless_forum');
        $this->assertFalse(testable_transcoder::is_enabled());

        $this->assertSame([], testable_transcoder::$commands);
        $this->assertNull(testable_transcoder::get_cached_availability());
    }

    /**
     * Building the admin tree (every admin page) checks nothing and writes nothing.
     *
     * @return void
     */
    public function test_admin_tree_build_does_not_check_ffmpeg(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();
        set_config('transcodeenabled', 1, 'local_textless_forum');

        $root = admin_get_root(true, true);

        $this->assertNotNull($root->locate('local_textless_forum'), 'The settings page must have been built');
        $this->assertNull(testable_transcoder::get_cached_availability(), 'The admin tree build ran the ffmpeg check');
        $this->assertFalse(get_config('local_textless_forum', 'ffmpegcheckedpath'));
        $this->assertEquals(1, get_config('local_textless_forum', 'transcodeenabled'));
    }

    /**
     * Saving the ffmpeg path queues a background check instead of checking inline.
     *
     * @return void
     */
    public function test_saving_path_queues_check(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();

        admin_write_settings((object) ['s_local_textless_forum_ffmpegpath' => '/usr/local/bin/ffmpeg']);

        $this->assertSame('/usr/local/bin/ffmpeg', get_config('local_textless_forum', 'ffmpegpath'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\check_ffmpeg::class));
        $this->assertNull(testable_transcoder::get_cached_availability());
    }

    /**
     * A transcode whose spawn throws fails cleanly instead of aborting the task.
     *
     * @return void
     */
    public function test_transcode_survives_a_spawn_that_throws(): void {
        testable_transcoder::check_ffmpeg();
        testable_transcoder::$result = new \RuntimeException('popen(), proc_open() etc. are unsupported');

        $fs = get_file_storage();
        $source = $fs->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_textless_forum',
            'filearea' => 'test',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'audio-test.webm',
        ], 'not really a recording');

        $this->assertNull(testable_transcoder::transcode($source, 'mp3'));
        $this->assertCount(2, testable_transcoder::$commands);
        $this->assertStringContainsString('libmp3lame', testable_transcoder::$commands[1]);
    }
}
