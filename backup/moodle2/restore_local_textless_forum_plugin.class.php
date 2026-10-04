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

/**
 * Restore support for local_textless_forum.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_textless_forum\manager;

/**
 * Restores the per-forum textless configuration backed up with each forum.
 *
 * The settings element is read while the module information is restored,
 * before the forum instance itself exists, so the row is stashed and only
 * written in after_restore_module() once the new forum id is known.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_textless_forum_plugin extends restore_local_plugin {
    /** @var \stdClass|null Settings read from the backup, pending the new forum id. */
    protected $pendingsettings = null;

    /**
     * Declare the paths handled at module level.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('textless', $this->get_pathfor('/textless')),
        ];
    }

    /**
     * Stash one textless settings element until the activity exists.
     *
     * @param array|\stdClass $data
     * @return void
     */
    public function process_textless($data) {
        $this->pendingsettings = (object) $data;
    }

    /**
     * Write the stashed settings now that the restored forum instance exists.
     *
     * @return void
     */
    public function after_restore_module() {
        if ($this->pendingsettings === null || $this->task->get_modulename() !== 'forum') {
            return;
        }

        // A backup file is untrusted input: go through the same checks as the
        // activity settings form (mode allowlist, negative length clamp) and
        // the same upsert on the forum.
        $data = $this->pendingsettings;
        manager::save_settings(
            (int) $this->task->get_activityid(),
            !empty($data->enabled),
            clean_param($data->recordingmode ?? '', PARAM_ALPHA),
            isset($data->maxduration) ? (int) $data->maxduration : manager::DEFAULT_MAX_DURATION
        );
    }
}
