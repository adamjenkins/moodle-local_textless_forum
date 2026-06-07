# Textless forum (local_textless_forum)

A Moodle local plugin that augments **mod_forum** so that selected forums accept
**only RecordRTC-recorded audio or video** as the body of a post. Typing and every
other editor tool are removed from the message box — the only way to add content
is to record it.

The post **subject** can still be typed as normal; it is the **message** that
must be a recording.

## Features

- Per-forum setting to make a forum *textless* (RecordRTC only).
- Per-forum setting to restrict recordings to **audio only**, **video only**, or
  **either**.
- The message editor (TinyMCE) is replaced with a recorder: text input,
  toolbars, menus and all other editor functions are disabled.
- Recordings are captured in the browser with `getUserMedia` + `MediaRecorder`
  and stored against the post exactly like any other forum editor attachment.
- When recording video, the camera preview opens first and recording only
  begins once the user clicks **Start recording** — nobody is captured before
  they're ready.
- The in-page **Reply** button on a discussion is rerouted to the same
  RecordRTC-only editor, so replying to another person's post obeys the same
  restriction.
- Site-wide admin settings control the requested recording **bitrates** and
  can automatically **transcode** recordings in the background to more widely
  compatible formats (MP3 for audio, MP4 for video) using `ffmpeg`.

## Requirements

- Moodle 4.5 (2024100700) or later — the plugin uses the Hooks API.
- A browser that supports the RecordRTC `MediaRecorder` API (current Chrome,
  Firefox, Edge and Safari).
- The site must be served over **HTTPS** (or `localhost`); browsers only grant
  microphone/camera access in a secure context.
- Optional: an `ffmpeg` binary on the server if you want recordings
  transcoded to other formats (see [Site-wide settings](#site-wide-settings)).

## Installation

1. Copy the plugin into your Moodle install so it lives at
   `<wwwroot>/local/textless_forum`.

   > On Moodle 5.1+ (which uses the `public/` webroot) the path is
   > `<moodle>/public/local/textless_forum`.

2. Log in as an administrator and visit **Site administration → Notifications**
   to complete the database installation.

## Usage

### Make a forum textless

1. Add or edit a **Forum** activity.
2. Open the **Textless forum (RecordRTC only)** section of the settings form.
3. Tick **Make this forum textless**.
4. Choose the **Allowed recording type**: *Audio or video*, *Audio only* or
   *Video only*.
5. Save the activity.

### Posting in a textless forum

When adding a discussion, replying, or editing a post in a textless forum, the
message editor is replaced with recorder controls:

- Click **Record audio** or **Record video** (only the buttons permitted by the
  forum's setting are shown).
- Allow the browser to use your microphone/camera when prompted.
- For **video**, the camera preview appears first so you can check framing —
  recording does not start yet. Click **Start recording** when ready, or
  **Cancel** to back out. (Audio recording starts immediately, since there is
  nothing to preview.)
- Click **Stop recording** when finished. The clip is uploaded and attached to
  the post automatically.
- Use **Record again** to discard the clip and start over.

Clicking **Reply** on someone else's post takes the user to the same recorder
rather than the plain-text quick reply, so the restriction is enforced
everywhere a post can be created.

## Site-wide settings

Visit **Site administration → Plugins → Local plugins → Textless forum** to
configure:

### Recording quality

- **Audio bitrate** / **Video bitrate** — requested from the browser's
  `MediaRecorder` (via `audioBitsPerSecond`/`videoBitsPerSecond`). Higher
  values mean better quality and larger files. The video bitrate only applies
  to video recordings; the audio bitrate applies to both.

### Recording transcoding

When enabled, every recording posted to a textless forum is additionally
converted in the background (via an adhoc task) to a more widely compatible
format, while the original is kept too:

- **Path to ffmpeg** — the full path to the `ffmpeg` executable on the server.
  This is treated as a system executable path: when `$CFG->preventexecpath` is
  set in `config.php`, the field is locked read-only, matching core settings
  such as `pathtogs`.
- **Transcode recordings** — the master switch. It is only offered (and is
  otherwise forced off with an explanatory notice) when `ffmpeg` can actually
  be run at the configured path.
- **Audio format** / **Video format** — choose *Don't convert*, or convert
  audio to **MP3** and/or video to **MP4 (H.264/AAC)**, mirroring the simple
  "convert or don't" choice offered by `tiny_recordrtc`'s "Audio format"
  setting.

Once a transcoded copy is ready, it is added to the post as an additional
`<source>` so the browser can use whichever format it supports.

## How it works

| Area | Implementation |
| --- | --- |
| Settings on the forum form | `lib.php` implements the `coursemodule_standard_elements` and `coursemodule_edit_post_actions` callbacks, filtered to `forum` activities. |
| Stored configuration | The `local_textless_forum` table (one row per forum), accessed through `\local_textless_forum\manager`. |
| Front-end enforcement | `\local_textless_forum\hook_callbacks` listens on `before_footer_html_generation` and loads the `local_textless_forum/textless` AMD module on textless-forum pages. |
| Recorder | `amd/src/textless.js` + `templates/recorder.mustache`: removes the editor, captures audio/video, and writes a `<video>`/`<audio>` embed into the message. |
| Upload endpoint | `upload.php` stores the recording in the editor's draft area (capability- and mode-checked) and returns a draftfile URL. |
| Reply rerouting | The AMD module intercepts the in-page reply buttons and sends users to the advanced (RecordRTC) editor. |
| Site-wide settings | `settings.php` adds a settings page under **Local plugins** for recording bitrates and transcoding, backed by `\local_textless_forum\manager` and `\local_textless_forum\transcoder`. |
| Transcoding | `\local_textless_forum\observer` queues a `\local_textless_forum\task\transcode_recording` adhoc task (via `\local_textless_forum\transcoder::queue_for_post`) whenever a post is created or edited in a textless forum; the task runs `ffmpeg` and adds the converted file to the post as an extra `<source>`. |
| Cleanup | `\local_textless_forum\observer` removes a forum's configuration when the activity is deleted. |

## Privacy

This plugin stores only per-forum configuration and holds no personal data.
Recorded media is stored against the forum post by mod_forum itself, which
manages its own privacy. See `\local_textless_forum\privacy\provider`.

## Building the JavaScript

The compiled module in `amd/build/` is committed so the plugin works without a
build step. If you change `amd/src/textless.js`, rebuild it from your Moodle
root with:

```bash
grunt amd --root=local/textless_forum
```

## License

GNU GPL v3 or later — see <https://www.gnu.org/licenses/gpl-3.0.html>.
