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
 * Stores a RecordRTC recording into the user's draft file area for the forum editor.
 *
 * The recorded blob is uploaded here from the browser, saved into the draft area
 * referenced by the forum post editor, and a draftfile URL is returned so the
 * client can embed it in the message. mod_forum then moves the file to the post
 * when the form is submitted, exactly as it does for any editor attachment.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_textless_forum\recording;

require_login();
require_sesskey();

$draftitemid = required_param('itemid', PARAM_INT);
$contextid = required_param('contextid', PARAM_INT);
$mediatype = required_param('mediatype', PARAM_ALPHA);

$context = context::instance_by_id($contextid, MUST_EXIST);

if (!isset($_FILES['recording']) || !is_uploaded_file($_FILES['recording']['tmp_name'])) {
    throw new moodle_exception('norecordingfound', 'local_textless_forum');
}

if (!empty($_FILES['recording']['error'])) {
    throw new moodle_exception('uploadfailed', 'local_textless_forum');
}

// The forum, capability, mode, content type, size and length checks are all made here.
$storedfile = recording::store($context, $draftitemid, $mediatype, $_FILES['recording']['tmp_name']);
$filename = $storedfile->get_filename();

$url = moodle_url::make_draftfile_url($draftitemid, '/', $filename)->out(false);

echo json_encode([
    'url' => $url,
    'filename' => $filename,
    'mimetype' => $storedfile->get_mimetype(),
]);
