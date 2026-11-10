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
 * Builds WebM (Matroska) recordings for the tests, laid out as browsers write them.
 *
 * No media tool is available to make real recordings, so the container
 * structure is assembled here: the EBML header, a Segment of unknown size,
 * a SeekHead, Info, Tracks and a Cluster of unknown size whose payload is
 * filler. The layout follows current Chromium MediaRecorder output, whose
 * SeekHead lists the Cluster position, so the Cluster ID bytes appear
 * before the Tracks element.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webm_builder {
    /**
     * An EBML element with a known size.
     *
     * @param string $id the element ID bytes
     * @param string $payload the element data
     * @return string the encoded element
     */
    public static function element(string $id, string $payload): string {
        // An 8-byte size, as Chromium's muxer writes for the master elements.
        return $id . "\x01" . substr(pack('J', strlen($payload)), 1) . $payload;
    }

    /**
     * A TrackEntry.
     *
     * @param int $number the track number
     * @param int $type the TrackType: 1 video, 2 audio
     * @param string $codecid the CodecID, e.g. V_VP8 or A_OPUS
     * @return string the encoded element
     */
    public static function track(int $number, int $type, string $codecid): string {
        return self::element("\xAE", "\xD7\x81" . chr($number) . "\x83\x81" . chr($type)
            . "\x86" . chr(0x80 | strlen($codecid)) . $codecid);
    }

    /**
     * A complete recording.
     *
     * @param string[] $tracks the encoded TrackEntry elements
     * @param string $payload the media data inside the Cluster
     * @param int $size pad the recording to this many bytes, 0 for no padding
     * @return string the file content
     */
    public static function recording(array $tracks, string $payload = '', int $size = 0): string {
        $ebml = self::element("\x1A\x45\xDF\xA3", "\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08"
            . "\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02");
        $seek = fn(string $id) => self::element("\x4D\xBB", "\x53\xAB\x84" . $id . "\x53\xAC\x81\x00");
        $seekhead = self::element("\x11\x4D\x9B\x74", $seek("\x15\x49\xA9\x66") . $seek("\x16\x54\xAE\x6B")
            . $seek("\x1F\x43\xB6\x75"));
        $info = self::element("\x15\x49\xA9\x66", "\x2A\xD7\xB1\x83\x0F\x42\x40\x4D\x80\x86Chrome\x57\x41\x86Chrome");
        $trackselement = self::element("\x16\x54\xAE\x6B", implode('', $tracks));
        $unknown = "\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF";
        $cluster = "\x1F\x43\xB6\x75" . $unknown . "\xE7\x81\x00\xA3" . chr(0x80 | min(strlen($payload) + 4, 0x7F))
            . "\x81\x00\x00\x80" . $payload;
        $content = $ebml . "\x18\x53\x80\x67" . $unknown . $seekhead . $info . $trackselement . $cluster;

        return $size ? str_pad($content, $size, "\x55") : $content;
    }

    /**
     * An audio-only recording (Opus), whose media data contains "V_VP8".
     *
     * The payload is there on purpose: media data must not be mistaken for a
     * video track header.
     *
     * @param int $size the total size in bytes
     * @return string the file content
     */
    public static function audio(int $size = 2000): string {
        return self::recording([self::track(1, 2, 'A_OPUS')], 'V_VP8', $size);
    }

    /**
     * A video recording as current Chromium writes it: VP8 video and Opus audio.
     *
     * @param int $size the total size in bytes
     * @return string the file content
     */
    public static function chromium_video(int $size = 2000): string {
        return self::recording([self::track(1, 2, 'A_OPUS'), self::track(2, 1, 'V_VP8')], '', $size);
    }
}
