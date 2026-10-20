# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

"Reserve a Room" — a single-file WordPress plugin (`mcw-reserve-a-room.php`) for library study-room booking. No build step, no dependencies, no test suite. Staff configure rooms/rules in wp-admin; patrons book via the `[reserve_a_room]` shortcode.

## Commands

- Syntax check: `php -l mcw-reserve-a-room.php` (PHP is on PATH). Must stay compatible with PHP 7.2 / WP 5.6 (header minimums).
- There is no local WP environment in the repo; behavior changes are verified by installing the release zip on a WordPress site.

## Release workflow (every change)

1. Bump `Version:` in the plugin header (SemVer). `MCW_ROOMS_VERSION` is read from the header via `get_file_data()` — don't hardcode it elsewhere.
2. Add an entry at the top of `## Changelog` in `README.md`.
3. Commit, push, and `gh release create vX.Y.Z` with asset `mcw-reserve-a-room.zip`. The zip must contain a single top-level `mcw-reserve-a-room/` folder holding the `.php` file (plus README/LICENSE) — WordPress derives plugin identity from that folder name, so renaming it makes WP treat it as a different plugin.

## Naming constraints

- User-facing text must not say "MCW" (plugin name, UI copy, README).
- Internal identifiers keep the `mcw` prefix and must NOT be renamed without a migration: the `mcw_rooms` option, `{prefix}mcw_room_slots` table, `mcw-rooms/v1` REST namespace, `MCW_ROOMS_*` constants, function/CSS prefixes, and the `mcw-reserve-a-room` admin page slug / plugin folder.

## Architecture

Everything lives in one PHP file, organized into commented sections. Front-end and admin UIs are inline `<style>`/`<script>` (vanilla JS, no libraries) emitted from PHP heredoc-style functions (`mcw_rooms_editor_markup`, `mcw_rooms_widget_markup`, `mcw_rooms_widget_script`); config is passed to JS via `window.MCW_ROOMS` (admin) and `window.MCW_ROOMS_CFG` (shortcode).

**Storage**
- Rooms + rules: one WP option (`mcw_rooms`), shape `{rules:{slotMin,maxMin,advanceDays,dailyMaxMin}, rooms:[{id,name,capacity,notes}]}`. `mcw_rooms_get()` back-fills rules added later from `mcw_rooms_defaults()`; when adding a rule, add it to defaults, `mcw_rooms_sanitize()`, and the editor JS.
- Bookings: custom table, **one row per occupied 30-minute slot**. A multi-slot booking is several rows sharing a 32-char `booking_id` (which doubles as the email cancellation token). `UNIQUE(room_id, slot_start)` is what prevents double-booking, including under concurrency — the insert is a single multi-row INSERT, and a "duplicate" DB error maps to a 409. Schema changes go through `mcw_rooms_install()` (dbDelta) + bump `MCW_ROOMS_DB_VERSION`.

**Hours dependency** — bookable time comes from the separate Library Hours plugin (github.com/tfscharff/wphours), read directly from its `mcw_library_hours` option (not an API). `mcw_room_open_blocks()` mirrors that plugin's precedence: single-date exception > term range > weekly default; guest hours are ignored. Timezone also comes from that option (fallback `America/New_York`). If the option is missing, availability returns 503.

**Slot model** — 30-min granularity is fixed. `mcw_room_candidate_slots()` lists slot starts fully inside open blocks; `mcw_rooms_reserve()` requires every slot of a booking to be a candidate, which enforces in-hours and no spanning across closed gaps.

**REST (`mcw-rooms/v1`)** — `GET /config`, `GET /availability?date=`, `POST /reserve` (public; protected by `wp_rest` nonce + `mcw_hp` honeypot field), `POST /config` (`edit_pages`). The daily per-patron limit is checked before insert and re-checked after insert (rolling back by `booking_id`) to close the race window.

**Other entry points** — cancellation via `?mcw_cancel=<token>` on any front-end URL (handled in `template_redirect`, renders a standalone page); admin menu "Reserve a Room" (editor) + "Reservations" submenu (list with nonce-protected staff cancel). Confirmation emails use `MCW_ROOMS_REPLY_TO`.

**Front-end widget** — the 12h/24h toggle is display-only; all times sent to the server and stored are 24-hour `HH:MM`. Accessibility matters here (keyboard grid navigation, aria-labels, pa11y-clean): preserve it when touching widget markup.
