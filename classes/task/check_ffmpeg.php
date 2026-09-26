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

namespace local_textless_forum\task;

use local_textless_forum\transcoder;

/**
 * Adhoc task that checks whether the configured ffmpeg command can be run.
 *
 * Queued by {@see transcoder::queue_ffmpeg_check()} when the transcoding
 * settings are saved. Checking spawns a process, which is done here in the
 * background rather than while rendering the settings page.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_ffmpeg extends \core\task\adhoc_task {
    /**
     * Return the name of the task as shown in the admin UI.
     *
     * @return string the localised task name
     */
    public function get_name() {
        return get_string('checkffmpegtaskname', 'local_textless_forum');
    }

    /**
     * Check ffmpeg and store the result.
     *
     * @return void
     */
    public function execute() {
        $available = transcoder::check_ffmpeg();
        mtrace('local_textless_forum: ffmpeg "' . transcoder::ffmpeg_path() . '" is '
            . ($available ? 'available' : 'not available; transcoding has been switched off'));
    }
}
