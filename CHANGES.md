# Changes

## v1.1.0

- Per-forum settings now survive course backup/restore and are cleaned up
  when their course is deleted.
- CI tests Moodle 5.0, 5.1 and 5.2 with compatible PHP versions
  (5.0: 8.2-8.3, 5.1: 8.2-8.4, 5.2: 8.3-8.4).

## v1.0.0

First public release.

- Turns chosen forums "textless": students reply with in-browser audio or
  video recordings instead of typed text.
- Per-forum recording mode (audio, video or both) and maximum duration;
  optional background transcoding of recordings.
- Recordings are ordinary forum attachments, so grading, backup and privacy
  flows all work as for any forum post.
