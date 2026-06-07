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
 * Tests for the textless forum configuration manager.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_textless_forum\manager
 */
final class manager_test extends \advanced_testcase {
    /**
     * A forum with no stored configuration is not textless.
     *
     * @return void
     */
    public function test_defaults_when_unset(): void {
        $this->resetAfterTest();

        $this->assertNull(manager::get_settings(12345));
        $this->assertFalse(manager::is_textless(12345));
        $this->assertSame(manager::MODE_BOTH, manager::get_mode(12345));
    }

    /**
     * Settings can be created, read back and updated.
     *
     * @return void
     */
    public function test_save_and_update(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        manager::save_settings($forum->id, true, manager::MODE_VIDEO);

        $this->assertTrue(manager::is_textless($forum->id));
        $this->assertSame(manager::MODE_VIDEO, manager::get_mode($forum->id));

        // Updating overwrites the existing record rather than creating a new one.
        manager::save_settings($forum->id, true, manager::MODE_AUDIO);
        $this->assertSame(manager::MODE_AUDIO, manager::get_mode($forum->id));

        $records = $this->get_record_count($forum->id);
        $this->assertSame(1, $records);

        // Disabling keeps the record but reports the forum as not textless.
        manager::save_settings($forum->id, false, manager::MODE_AUDIO);
        $this->assertFalse(manager::is_textless($forum->id));
    }

    /**
     * An invalid mode falls back to "both".
     *
     * @return void
     */
    public function test_invalid_mode_falls_back(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        manager::save_settings($forum->id, true, 'nonsense');
        $this->assertSame(manager::MODE_BOTH, manager::get_mode($forum->id));
    }

    /**
     * Deleting settings removes the stored record.
     *
     * @return void
     */
    public function test_delete(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        manager::save_settings($forum->id, true, manager::MODE_BOTH);
        manager::delete_settings($forum->id);

        $this->assertNull(manager::get_settings($forum->id));
    }

    /**
     * Count the stored configuration records for a forum.
     *
     * @param int $forumid the forum instance id
     * @return int the number of records
     */
    private function get_record_count(int $forumid): int {
        global $DB;

        return $DB->count_records(manager::TABLE, ['forum' => $forumid]);
    }
}
