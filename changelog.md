# Changelog

All notable changes to Textless forum (`local_textless_forum`) are documented in this file.

## [Unreleased]

### Security

- The recording-only rule is enforced on the server, not only by the
  recorder in the browser: the `post_created`, `post_updated` and (new)
  `discussion_created` observers strip everything but the recordings the
  forum's mode allows from a textless forum's post
  (`manager::enforce_recording_only()`).
- `upload.php` now hands the upload to `recording::store()`, which checks the
  content: the file must be a WebM/Matroska, MP4 or Ogg container, and must
  contain a video track exactly when it is declared as video, so the forum's
  audio-only/video-only mode can no longer be bypassed with a client-sent
  `mediatype`. The file name is chosen by the server. The site, course and
  forum upload limits apply (`get_user_max_upload_file_size()`), and the
  forum's maximum length bounds the size (twice the recorder bitrate for that
  length, plus 256 KB).
- The post-save enforcement re-checks every file a textless post embeds
  (`recording::is_acceptable_stored_file()`), because core's draft upload
  accepts any file: a `<source>` is kept only when its file in the post's own
  area is a WebM/MP4/Ogg container with a video track exactly when embedded as
  `<video>`, within the maximum-length size budget. The transcoded fallback is
  kept only when it is the server-created copy (no owning user) of a source
  that passed.
- The transcoding task pins ffmpeg's input format (`-f matroska|mov|ogg`) and
  protocols (`-protocol_whitelist file`), and skips files that are not
  recordings, so playlist or concat inputs cannot reach ffmpeg's demuxers.

### Fixed

- Restore writes the textless settings through `manager::save_settings()`,
  so a crafted backup cannot store an unknown mode or a negative maximum
  length.
- Recordings in the first post of a new discussion are transcoded too
  (mod_forum triggers `discussion_created`, not `post_created`, for it).

### Changed

- `$plugin->requires` raised to 2025041400 (Moodle 5.0), the lowest
  supported branch.
- PHPUnit tests added for the upload checks, the observers, backup/restore,
  the transcoder's command line and `add_source_to_message()`. The Behat CI
  step was removed: there are no features, so it could only pass vacuously.

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
