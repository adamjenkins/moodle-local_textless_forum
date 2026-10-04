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
 * Tests that the declared requirements agree with each other.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class version_test extends \basic_testcase {
    /**
     * $plugin->requires is not below the lowest branch in $plugin->supported.
     *
     * @return void
     */
    public function test_requires_matches_lowest_supported_branch(): void {
        // The release build of each branch that can be the lowest supported one
        // (version.php at the v5.0.0, v5.1.0 and v5.2.0 tags).
        $branchfloors = [
            500 => 2025041400,
            501 => 2025100600,
            502 => 2026042000,
        ];

        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');

        $lowest = $plugin->supported[0];
        $this->assertArrayHasKey($lowest, $branchfloors);
        $this->assertGreaterThanOrEqual($branchfloors[$lowest], $plugin->requires);
    }
}
