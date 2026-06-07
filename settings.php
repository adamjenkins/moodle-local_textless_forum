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
 * Site-wide admin settings for local_textless_forum.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_textless_forum\manager;
use local_textless_forum\transcoder;

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_textless_forum',
        get_string('pluginname', 'local_textless_forum')
    );
    $ADMIN->add('localplugins', $settings);

    // Recording quality.
    $settings->add(new admin_setting_heading(
        'local_textless_forum/qualityheader',
        get_string('qualityheader', 'local_textless_forum'),
        get_string('qualityheader_desc', 'local_textless_forum')
    ));

    $audiobitrates = [24000, 32000, 48000, 64000, 96000, 128000, 160000, 192000, 256000, 320000];
    $audiobitrateoptions = [];
    foreach ($audiobitrates as $rate) {
        $audiobitrateoptions[$rate] = get_string('kbrate', 'local_textless_forum', $rate / 1000);
    }
    $settings->add(new admin_setting_configselect(
        'local_textless_forum/audiobitrate',
        get_string('audiobitrate', 'local_textless_forum'),
        get_string('audiobitrate_desc', 'local_textless_forum'),
        manager::DEFAULT_AUDIO_BITRATE,
        $audiobitrateoptions
    ));

    $settings->add(new admin_setting_configtext(
        'local_textless_forum/videobitrate',
        get_string('videobitrate', 'local_textless_forum'),
        get_string('videobitrate_desc', 'local_textless_forum'),
        manager::DEFAULT_VIDEO_BITRATE,
        PARAM_INT,
        8
    ));

    // Recording transcoding.
    $settings->add(new admin_setting_heading(
        'local_textless_forum/transcodeheader',
        get_string('transcodeheader', 'local_textless_forum'),
        get_string('transcodeheader_desc', 'local_textless_forum')
    ));

    // A path to a system executable: admin_setting_configexecutable already
    // respects $CFG->preventexecpath, locking the field read-only and showing
    // the standard "exec path not allowed" notice when it is set.
    $settings->add(new admin_setting_configexecutable(
        'local_textless_forum/ffmpegpath',
        get_string('ffmpegpath', 'local_textless_forum'),
        get_string('ffmpegpath_desc', 'local_textless_forum'),
        '/usr/bin/ffmpeg'
    ));

    if (!transcoder::is_ffmpeg_available()) {
        // The prerequisite is missing: force the feature off and explain why,
        // rather than offering a control that cannot work.
        set_config('transcodeenabled', 0, 'local_textless_forum');

        $warning = html_writer::div(
            get_string('ffmpegmissing', 'local_textless_forum', s(transcoder::ffmpeg_path())),
            'alert alert-warning'
        );
        $settings->add(new admin_setting_description('local_textless_forum/ffmpegmissing', '', $warning));
    } else {
        $settings->add(new admin_setting_configcheckbox(
            'local_textless_forum/transcodeenabled',
            get_string('transcodeenabled', 'local_textless_forum'),
            get_string('transcodeenabled_desc', 'local_textless_forum'),
            0
        ));
    }

    // Audio format selection, mirroring the simple "Default vs MP3" choice
    // offered by tiny_recordrtc's "audiortcformat" setting.
    $settings->add(new admin_setting_configselect(
        'local_textless_forum/transcodeaudioformat',
        get_string('transcodeaudioformat', 'local_textless_forum'),
        get_string('transcodeaudioformat_desc', 'local_textless_forum'),
        transcoder::FORMAT_NONE,
        [
            transcoder::FORMAT_NONE => get_string('transcodeformatnone', 'local_textless_forum'),
            'mp3' => get_string('format_mp3', 'local_textless_forum'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'local_textless_forum/transcodevideoformat',
        get_string('transcodevideoformat', 'local_textless_forum'),
        get_string('transcodevideoformat_desc', 'local_textless_forum'),
        transcoder::FORMAT_NONE,
        [
            transcoder::FORMAT_NONE => get_string('transcodeformatnone', 'local_textless_forum'),
            'mp4' => get_string('format_mp4', 'local_textless_forum'),
        ]
    ));
}
