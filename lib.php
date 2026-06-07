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
 * Library callbacks for local_textless_forum.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_textless_forum\manager;

/**
 * Add the textless options to the forum activity settings form.
 *
 * This callback runs for every course module settings form, so it returns
 * early for anything that is not a forum.
 *
 * @param moodleform_mod $formwrapper the form wrapper exposing the module being edited
 * @param MoodleQuickForm $mform the raw form being built
 * @return void
 */
function local_textless_forum_coursemodule_standard_elements($formwrapper, $mform) {
    $current = $formwrapper->get_current();

    if (empty($current->modulename) || $current->modulename !== 'forum') {
        return;
    }

    $mform->addElement('header', 'textlessforumheader', get_string('settingsheader', 'local_textless_forum'));

    $mform->addElement(
        'advcheckbox',
        'textlessenabled',
        get_string('enable', 'local_textless_forum'),
        get_string('enable_desc', 'local_textless_forum')
    );
    $mform->setType('textlessenabled', PARAM_BOOL);
    $mform->addHelpButton('textlessenabled', 'enable', 'local_textless_forum');
    $mform->setDefault('textlessenabled', 0);

    $mform->addElement(
        'select',
        'textlessmode',
        get_string('mode', 'local_textless_forum'),
        manager::get_mode_menu()
    );
    $mform->setType('textlessmode', PARAM_ALPHA);
    $mform->setDefault('textlessmode', manager::MODE_BOTH);
    $mform->addHelpButton('textlessmode', 'mode', 'local_textless_forum');
    $mform->hideIf('textlessmode', 'textlessenabled', 'notchecked');

    $mform->addElement(
        'duration',
        'textlessmaxduration',
        get_string('maxduration', 'local_textless_forum'),
        ['optional' => false, 'defaultunit' => 1]
    );
    $mform->setType('textlessmaxduration', PARAM_INT);
    $mform->setDefault('textlessmaxduration', manager::DEFAULT_MAX_DURATION);
    $mform->addHelpButton('textlessmaxduration', 'maxduration', 'local_textless_forum');
    $mform->hideIf('textlessmaxduration', 'textlessenabled', 'notchecked');

    // Pre-fill with the values stored for an existing forum.
    $forumid = (int) $formwrapper->get_instance();
    if ($forumid && ($settings = manager::get_settings($forumid))) {
        $mform->setDefault('textlessenabled', $settings->enabled);
        $mform->setDefault('textlessmode', $settings->recordingmode);
        $mform->setDefault('textlessmaxduration', $settings->maxduration);
    }
}

/**
 * Persist the textless options when a forum is created or updated.
 *
 * @param stdClass $data the submitted module data
 * @param stdClass $course the course the module belongs to
 * @return stdClass the (unchanged) module data
 */
function local_textless_forum_coursemodule_edit_post_actions($data, $course) {
    // Only act for forums that actually rendered our form fields.
    if (empty($data->modulename) || $data->modulename !== 'forum' || !isset($data->textlessenabled)) {
        return $data;
    }

    $forumid = (int) ($data->instance ?? 0);
    if (!$forumid) {
        return $data;
    }

    $mode = $data->textlessmode ?? manager::MODE_BOTH;
    $maxduration = (int) ($data->textlessmaxduration ?? manager::DEFAULT_MAX_DURATION);
    manager::save_settings($forumid, !empty($data->textlessenabled), $mode, $maxduration);

    return $data;
}

/**
 * Legacy footer callback — queues the RecordRTC enforcer on textless forum pages.
 *
 * This duplicates the {@see \core\hook\output\before_footer_html_generation}
 * registration in db/hooks.php on purpose. Moodle skips this legacy callback
 * automatically when the modern hook is registered, so there is no double
 * injection; it only runs as a fallback when the cached hook registrations are
 * stale (the hook cache is not invalidated by plugin installs). It reaches the
 * front end via the reliable plugin-function discovery used by the rest of
 * lib.php.
 *
 * @return string always an empty string (no extra footer HTML is added)
 */
function local_textless_forum_before_footer() {
    \local_textless_forum\hook_callbacks::inject_enforcer();

    return '';
}
