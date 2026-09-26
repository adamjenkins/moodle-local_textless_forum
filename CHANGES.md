# Changes

## v1.1.1

- Fixed: admin pages no longer run ffmpeg. The plugin's settings used to run
  "ffmpeg -version" every time the admin tree was built (every admin page and
  admin search), which hung servers that cannot start processes, such as
  PHP-WASM sandboxes. ffmpeg is now checked in the background (by cron) when
  the transcoding settings are saved and before a recording is first
  transcoded, and the settings page shows the stored result.
- "Transcode recordings" stays visible with a notice when ffmpeg is missing
  or not yet checked; enabling it again re-runs the check. A failed check
  still switches transcoding off.
- If ffmpeg cannot be started at all (the process cannot be spawned, or
  `exec` is disabled), transcoding is treated as unavailable instead of
  failing the request.
- Releases are now published to Moodle Marketplace (replacing the retired
  moodle.org Plugins directory workflow).
- CI now tests Moodle 5.2 only (PHP 8.3 and 8.4, PostgreSQL and MariaDB).
  The declared supported range is unchanged (Moodle 5.0-5.2), but 5.0 and 5.1
  are no longer tested.
