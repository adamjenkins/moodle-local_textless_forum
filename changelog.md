# Changelog

All notable changes to Textless forum (`local_textless_forum`) are documented in this file.

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
