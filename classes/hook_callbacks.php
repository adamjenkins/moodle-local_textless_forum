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

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks that enforce the RecordRTC-only restriction on the front end.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var bool Guard so the enforcer is only ever queued once per request. */
    protected static $injected = false;

    /**
     * Load the front-end enforcer on forum pages that belong to a textless forum.
     *
     * The same JavaScript module locks down the advanced editor (post.php) and
     * reroutes the in-page "Reply" buttons on the discussion view to that editor,
     * so every entry point obeys the RecordRTC-only restriction.
     *
     * @param before_footer_html_generation $hook the footer generation hook
     * @return void
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        self::inject_enforcer();
    }

    /**
     * Queue the enforcer JavaScript when the current page is a textless forum.
     *
     * This is safe to call from more than one entry point: it only acts on forum
     * module pages, and a static guard ensures the JavaScript is queued at most
     * once per request.
     *
     * @return void
     */
    public static function inject_enforcer(): void {
        global $PAGE;

        if (self::$injected) {
            return;
        }

        // We need a configured page with a forum course module.
        //
        // Note: $PAGE exposes cm, context, etc. via __get() without a matching
        // __isset(), so empty($PAGE->cm) and isset($PAGE->cm) always behave as
        // though the property is unset. A strict null check is required.
        if ($PAGE->cm === null || $PAGE->cm->modname !== 'forum') {
            return;
        }

        $forumid = (int) $PAGE->cm->instance;
        if (!manager::is_textless($forumid)) {
            return;
        }

        $context = $PAGE->context;
        if ($context === null || $context->contextlevel != CONTEXT_MODULE) {
            // The forum module context is required for the upload endpoint.
            $context = \context_module::instance($PAGE->cm->id);
        }

        self::$injected = true;

        $PAGE->requires->js_call_amd('local_textless_forum/textless', 'init', [[
            'contextid' => $context->id,
            'mode' => manager::get_mode($forumid),
            'maxduration' => manager::get_max_duration($forumid),
        ]]);
    }
}
