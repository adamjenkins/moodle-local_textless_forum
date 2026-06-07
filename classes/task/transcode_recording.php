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
 * Adhoc task that converts a single saved recording to another format with ffmpeg.
 *
 * Queued by {@see transcoder::queue_for_post()} once a textless forum post has
 * been saved and its recording is sitting in its permanent file area.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcode_recording extends \core\task\adhoc_task {
    /**
     * Return the name of the task as shown in the admin UI.
     *
     * @return string the localised task name
     */
    public function get_name() {
        return get_string('transcodetaskname', 'local_textless_forum');
    }

    /**
     * Convert the queued recording, replacing any earlier transcoded copy.
     *
     * @return void
     */
    public function execute() {
        if (!transcoder::is_enabled()) {
            // The administrator may have switched transcoding off (or ffmpeg
            // may have stopped working) since this task was queued.
            return;
        }

        $data = (object) $this->get_custom_data();

        $fs = get_file_storage();
        $source = $fs->get_file(
            $data->contextid,
            $data->component,
            $data->filearea,
            $data->itemid,
            $data->filepath,
            $data->filename
        );

        if (!$source || $source->is_directory()) {
            // The recording has since moved or been deleted; nothing to do.
            return;
        }

        $result = transcoder::transcode($source, $data->targetformat);
        if ($result === null) {
            mtrace('local_textless_forum: failed to transcode "' . $data->filename . '" to ' . $data->targetformat);
            return;
        }

        if ($data->component === 'mod_forum' && $data->filearea === 'post') {
            transcoder::add_source_to_message((int) $data->itemid, $source, $result);
        }

        mtrace('local_textless_forum: transcoded "' . $data->filename . '" to ' . $result->get_filename());
    }
}
