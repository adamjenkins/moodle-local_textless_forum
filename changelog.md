# Changelog

All notable changes to Textless forum (`local_textless_forum`) are documented in this file.

## [1.1.2] - 2026-10-03

### Changed

- Declare Moodle 5.3 support (`$plugin->supported` upper bound raised from
  502 to 503).

## [1.1.1] - 2026-09-26

### Fixed

- The site settings no longer run "ffmpeg -version" while the admin tree is
  built (on every admin page and admin search). On runtimes that cannot spawn
  processes (PHP-WASM) the attempt never returns, which hung the whole
  sandbox. The check now runs in a new `check_ffmpeg` adhoc task, queued when
  the ffmpeg path or the transcoding switch is saved, and in the transcoding
  task before ffmpeg is first used; its result is stored per path and only
  read by the settings page and the post-saved observer.
- A spawn that throws, or an `exec` removed by `disable_functions`, now
  counts as ffmpeg being unavailable instead of aborting the request.

### Changed

- "Transcode recordings" is always shown, with an info notice while ffmpeg is
  unchecked and a warning when it is missing; enabling it again re-runs the
  check.
- Releases go to Moodle Marketplace through the
  `moodlehq/moodle-plugin-release` reusable workflow, replacing the retired
  moodle.org Plugins directory workflow.
- CI matrix reduced to Moodle 5.2 (PHP 8.3-8.4, PostgreSQL 16 and
  MariaDB 10.11). `$plugin->supported` still declares 5.0-5.2.
- Development files are kept out of the distribution ZIP (`.gitattributes`),
  and the camp listing manifest is complete.

### Verification

- PHPUnit: `local_textless_forum_testsuite`, 13 tests passing on Moodle 5.2.2+
  / PHP 8.4 / MariaDB; the new transcoder tests fail with either fix reverted.
- moodle-plugin-ci phplint, phpcs and phpdoc (`--max-warnings 0`), validate,
  savepoints and mustache all pass; grunt (eslint + stylelint) is clean.

## [1.1.0] - 2026-07-12

### Added

- Course lifecycle handling: a course_deleted observer sweeps configuration
  rows orphaned by course deletion (course deletion removes forums without
  firing course_module_deleted).
- Course backup and restore support: the per-forum textless configuration
  (enabled flag, recording mode, maximum duration) travels with the forum in
  course backups. The recordings themselves are ordinary forum attachments
  and were already covered by mod_forum's own backup.

### Changed

- CI matrix corrected: Moodle 5.0 is tested on PHP 8.2-8.3 only (not 8.4),
  alongside Moodle 5.1 (PHP 8.2-8.4) and 5.2 (PHP 8.3-8.4); the grunt
  stale-build check runs on the MOODLE_502_STABLE toolchain the committed
  bundles are built with.
- Added the moodle-release.yml workflow for automatic Moodle Plugins
  directory releases, and CHANGES.md release notes.

## [1.0.0] - 2026-06-08

Initial release.

- Per-forum "textless" mode: replaces typed forum posts with in-browser
  audio/video recordings (RecordRTC), with per-forum recording mode
  (audio/video/both) and maximum duration.
- Optional background transcoding of recordings via an ad-hoc task.
- Recordings are stored as regular forum post attachments.
