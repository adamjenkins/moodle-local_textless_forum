# Changes

## v1.1.3

- Textless forums are now enforced on the server: when a post is created or
  edited (including the first post of a discussion, and posts sent through web
  services or the mobile app), everything in its message except the
  recordings the forum's mode allows is removed.
- The recording upload checks the file itself: only real WebM, MP4 or Ogg
  recordings are accepted, a video sent as "audio" is refused in an audio-only
  forum, and the site, course and forum upload size limits apply. The
  forum's maximum recording length is also bounded on the server, through
  the file size.
- The same content and length checks are applied again when a post is
  saved, so a file put into the post without the recorder (through Moodle's
  own file upload) is removed from the message unless it is a real recording
  of the embedded type within the maximum length.
- Transcoding tells ffmpeg the recording's input format and lets it read
  local files only; files that are not recordings are not transcoded.
- Restoring a forum applies the same checks to its textless settings as the
  settings form (unknown modes become "Audio or video", negative lengths the
  default).
- Recordings at the start of a new discussion are now transcoded too.
- Requires Moodle 5.0 or later, matching the supported versions.
- The Composer package now accepts later Moodle 5.x releases
  (`moodle/moodle` `^5.0`) instead of stopping before 5.4. The supported
  versions declared in `version.php` are unchanged.
- New PHPUnit tests for the upload checks, the observers, backup and restore
  and the transcoder; the Behat CI step, which had no features to run, was
  removed. The automated tests now run against the released Moodle 5.3
  (`MOODLE_503_STABLE`) instead of Moodle's development branch, and those
  runs now count towards a pass.
- Each tagged release is also published to the camp plugin registry.

No database or capability changes. No action is required after upgrading.
