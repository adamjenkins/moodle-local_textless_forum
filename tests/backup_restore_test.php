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
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Tests that the per-forum configuration survives backup and restore, and that
 * restore treats the backup as untrusted input.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_local_textless_forum_plugin
 * @covers     \backup_local_textless_forum_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Back up a course into a temporary directory, without packing it.
     *
     * @param int $courseid the course to back up
     * @return string the backup id (the directory under the backup temp dir)
     */
    protected function backup(int $courseid): string {
        global $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        return $backupid;
    }

    /**
     * Restore a backup directory into a new course.
     *
     * @param string $backupid the backup id
     * @return int the new course id
     */
    protected function restore(string $backupid): int {
        global $USER;

        $newcourseid = \restore_dbops::create_new_course('Restored', 'restored', $this->getDataGenerator()->create_category()->id);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * The settings of the only forum in a restored course.
     *
     * @param int $courseid the restored course
     * @return \stdClass the restored configuration row
     */
    protected function restored_settings(int $courseid): \stdClass {
        global $DB;

        $forums = $DB->get_records('forum', ['course' => $courseid, 'type' => 'general']);
        $this->assertCount(1, $forums);
        $settings = manager::get_settings((int) reset($forums)->id);
        $this->assertNotNull($settings, 'The forum configuration was not restored');

        return $settings;
    }

    /**
     * Every setting travels with the forum and lands on the new forum id.
     *
     * @return void
     */
    public function test_round_trip(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        manager::save_settings($forum->id, true, manager::MODE_VIDEO, 45);

        $settings = $this->restored_settings($this->restore($this->backup($course->id)));

        $this->assertNotEquals($forum->id, $settings->forum);
        $this->assertEquals(1, $settings->enabled);
        $this->assertSame(manager::MODE_VIDEO, $settings->recordingmode);
        $this->assertEquals(45, $settings->maxduration);
        // The original is unchanged.
        $this->assertSame(manager::MODE_VIDEO, manager::get_mode($forum->id));
    }

    /**
     * Hostile values in the backup get the same checks as the settings form.
     *
     * @return void
     */
    public function test_restore_sanitises_hostile_values(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        manager::save_settings($forum->id, true, manager::MODE_AUDIO, 60);
        $backupid = $this->backup($course->id);

        // Tamper with the backup as a crafted .mbz would.
        $modulexml = $CFG->backuptempdir . '/' . $backupid . '/activities/forum_' . $forum->cmid . '/module.xml';
        $xml = file_get_contents($modulexml);
        $this->assertStringContainsString('<recordingmode>audio</recordingmode>', $xml);
        $xml = str_replace(
            ['<enabled>1</enabled>', '<recordingmode>audio</recordingmode>', '<maxduration>60</maxduration>'],
            ['<enabled>7</enabled>', '<recordingmode>none</recordingmode>', '<maxduration>-5</maxduration>'],
            $xml
        );
        file_put_contents($modulexml, $xml);

        $settings = $this->restored_settings($this->restore($backupid));

        $this->assertEquals(1, $settings->enabled);
        $this->assertSame(manager::MODE_BOTH, $settings->recordingmode);
        $this->assertEquals(manager::DEFAULT_MAX_DURATION, $settings->maxduration);
    }
}
