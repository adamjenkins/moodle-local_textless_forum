# Changes

## v1.1.4

- Fixed: video recordings made in current Chrome, Edge and other Chromium-based
  browsers were refused when uploaded ("That type of recording is not allowed
  in this forum."), and such
  videos were removed from the post when it was saved. The upload check now
  reads the WebM file's structure properly to find its video track, so these
  recordings are accepted again. Files that are not real recordings are still
  refused.
- The recorder reads the site address and session key through Moodle's
  standard JavaScript configuration module instead of a legacy global.
- The plugin has an icon on the plugins overview page.

No database or capability changes. No action is required after upgrading.
