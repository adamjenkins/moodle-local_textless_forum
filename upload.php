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

use local_textless_forum\manager;

require_login();
require_sesskey();

$draftitemid = required_param('itemid', PARAM_INT);
$contextid = required_param('contextid', PARAM_INT);
$mediatype = required_param('mediatype', PARAM_ALPHA);

$context = context::instance_by_id($contextid, MUST_EXIST);

if ($context->contextlevel != CONTEXT_MODULE) {
    throw new moodle_exception('invalidcontext', 'error');
}

// Resolve the forum behind this module context and confirm the user may post here.
$cm = get_coursemodule_from_id('forum', $context->instanceid, 0, false, MUST_EXIST);
require_login($cm->course, false, $cm);

if (!manager::is_textless((int) $cm->instance)) {
    throw new moodle_exception('nottextless', 'local_textless_forum');
}

// The recording has to be acceptable for the configured mode.
$mode = manager::get_mode((int) $cm->instance);
if (
    ($mode === manager::MODE_AUDIO && $mediatype !== 'audio')
        || ($mode === manager::MODE_VIDEO && $mediatype !== 'video')
) {
    throw new moodle_exception('recordingnotallowed', 'local_textless_forum');
}

// The user must be able to contribute to this forum in some way.
if (
    !has_capability('mod/forum:replypost', $context)
        && !has_capability('mod/forum:startdiscussion', $context)
) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('reply', 'mod_forum'));
}

if (!isset($_FILES['recording']) || !is_uploaded_file($_FILES['recording']['tmp_name'])) {
    throw new moodle_exception('norecordingfound', 'local_textless_forum');
}

if (!empty($_FILES['recording']['error'])) {
    throw new moodle_exception('uploadfailed', 'local_textless_forum');
}

$fs = get_file_storage();
$usercontext = context_user::instance($USER->id);

// Build a clean, unique filename inside the draft area.
$filename = clean_param($_FILES['recording']['name'], PARAM_FILE);
if ($filename === '') {
    $filename = ($mediatype === 'video' ? 'video' : 'audio') . '.webm';
}
$filename = $fs->get_unused_filename($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);

$filerecord = (object) [
    'contextid' => $usercontext->id,
    'component' => 'user',
    'filearea' => 'draft',
    'itemid' => $draftitemid,
    'filepath' => '/',
    'filename' => $filename,
    'userid' => $USER->id,
];

$storedfile = $fs->create_file_from_pathname($filerecord, $_FILES['recording']['tmp_name']);

$url = moodle_url::make_draftfile_url($draftitemid, '/', $filename)->out(false);

echo json_encode([
    'url' => $url,
    'filename' => $filename,
    'mimetype' => $storedfile->get_mimetype(),
]);
