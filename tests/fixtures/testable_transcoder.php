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
 * A transcoder whose process spawn is scripted by the test.
 *
 * @package    local_textless_forum
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_transcoder extends transcoder {
    /** @var \Throwable|array what the next command does: throw this, or return [output, exitcode] */
    public static $result = [[], 0];

    /** @var string[] every command that would have been spawned */
    public static $commands = [];

    /**
     * Record the command instead of spawning it.
     *
     * @param string $command the complete, already escaped command line
     * @return array [string[] $output, int $exitcode]
     */
    protected static function run_command(string $command): array {
        self::$commands[] = $command;
        if (self::$result instanceof \Throwable) {
            throw self::$result;
        }

        return self::$result;
    }

    /**
     * Forget the per-request ffmpeg result and the recorded commands.
     *
     * @return void
     */
    public static function reset(): void {
        self::$ffmpegavailable = null;
        self::$result = [[], 0];
        self::$commands = [];
    }

    /**
     * The per-request ffmpeg result, null when nothing has checked it.
     *
     * @return bool|null the cached result
     */
    public static function get_cached_availability(): ?bool {
        return self::$ffmpegavailable;
    }
}
