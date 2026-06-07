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
- The in-page **Reply** button on a discussion is rerouted to the same
  RecordRTC-only editor, so replying to another person's post obeys the same
  restriction.

## Requirements

- Moodle 4.5 (2024100700) or later — the plugin uses the Hooks API.
- A browser that supports the RecordRTC `MediaRecorder` API (current Chrome,
  Firefox, Edge and Safari).
- The site must be served over **HTTPS** (or `localhost`); browsers only grant
  microphone/camera access in a secure context.

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
- Click **Stop recording** when finished. The clip is uploaded and attached to
  the post automatically.
- Use **Record again** to discard the clip and start over.

Clicking **Reply** on someone else's post takes the user to the same recorder
rather than the plain-text quick reply, so the restriction is enforced
everywhere a post can be created.

## How it works

| Area | Implementation |
| --- | --- |
| Settings on the forum form | `lib.php` implements the `coursemodule_standard_elements` and `coursemodule_edit_post_actions` callbacks, filtered to `forum` activities. |
| Stored configuration | The `local_textless_forum` table (one row per forum), accessed through `\local_textless_forum\manager`. |
| Front-end enforcement | `\local_textless_forum\hook_callbacks` listens on `before_footer_html_generation` and loads the `local_textless_forum/textless` AMD module on textless-forum pages. |
| Recorder | `amd/src/textless.js` + `templates/recorder.mustache`: removes the editor, captures audio/video, and writes a `<video>`/`<audio>` embed into the message. |
| Upload endpoint | `upload.php` stores the recording in the editor's draft area (capability- and mode-checked) and returns a draftfile URL. |
| Reply rerouting | The AMD module intercepts the in-page reply buttons and sends users to the advanced (RecordRTC) editor. |
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
