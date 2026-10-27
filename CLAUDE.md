# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

"Reserve a Room" — a single-file WordPress plugin (`reserve-a-room.php`) for library study-room booking. No build step, no dependencies, no test suite. Staff configure rooms/rules in wp-admin; patrons book via the `[reserve_a_room]` shortcode.

## Commands

- Syntax check: `php -l reserve-a-room.php` (PHP is on PATH). Must stay compatible with PHP 7.2 / WP 5.6 (header minimums).
- There is no local WP environment in the repo; behavior changes are verified by installing the release zip on a WordPress site.

## Release workflow (every change)

1. Bump `Version:` in the plugin header (SemVer). `RAR_VERSION` is read from the header via `get_file_data()` — don't hardcode it elsewhere.
2. Add an entry at the top of `## Changelog` in `README.md`.
3. Commit, push, and `gh release create vX.Y.Z` with asset `reserve-a-room.zip`. The zip must contain a single top-level `reserve-a-room/` folder holding the `.php` file (plus README/LICENSE) — WordPress derives plugin identity from that folder name, so renaming it makes WP treat it as a different plugin.

## Naming constraints

- Don't use "MCW" anywhere. Prefixes are `rar_` (PHP functions, options, error codes), `RAR_` (constants), `rar-` (CSS classes/ids), `reserve-a-room` (REST namespace, admin page slug, plugin folder).
- Renaming stored identifiers (the `rar_rooms` option, `{prefix}rar_slots` table, REST namespace, plugin folder) breaks live sites unless it comes with a migration. See `rar_migrate_legacy()`, which moves pre-2.0 `mcw_*` data over.
- Exceptions that must keep "mcw": `mcw_library_hours` is the Library Hours plugin's option (not ours), and `?mcw_cancel=` is still accepted so cancel links in emails sent before 2.0 keep working.

## Architecture

Everything lives in one PHP file, organized into commented sections. Front-end and admin UIs are inline `<style>`/`<script>` (vanilla JS, no libraries) emitted from PHP heredoc-style functions (`rar_editor_markup`, `rar_widget_markup`, `rar_widget_script`); config is passed to JS via `window.RAR_ADMIN` (admin) and `window.RAR_CFG` (shortcode).

**Storage**
- Rooms + rules: one WP option (`rar_rooms`), shape `{rules:{slotMin,maxMin,advanceDays,dailyMaxMin,timeFormat,emailDomains}, rooms:[{id,name,capacity,notes}]}`. `rar_get()` back-fills rules added later from `rar_defaults()`; when adding a rule, add it to defaults, `rar_sanitize()`, and the editor JS.
- Bookings: custom table, **one row per occupied 30-minute slot**. A multi-slot booking is several rows sharing a 32-char `booking_id` (which doubles as the email cancellation token). `UNIQUE(room_id, slot_start)` is what prevents double-booking, including under concurrency — the insert is a single multi-row INSERT, and a "duplicate" DB error maps to a 409. Schema changes go through `rar_install()` (dbDelta) + bump `RAR_DB_VERSION`; a `plugins_loaded` check re-runs install when the stored `rar_db_version` is behind.

**Hours dependency** — bookable time comes from the separate Library Hours plugin (github.com/tfscharff/wphours), read directly from its `mcw_library_hours` option (not an API). `rar_open_blocks()` mirrors that plugin's precedence: single-date exception > term range > weekly default; guest hours are ignored. Timezone also comes from that option (fallback `America/New_York`). If the option is missing, availability returns 503.

**Slot model** — 30-min granularity is fixed. `rar_candidate_slots()` lists slot starts fully inside open blocks; `rar_reserve()` requires every slot of a booking to be a candidate, which enforces in-hours and no spanning across closed gaps.

**REST (`reserve-a-room/v1`)** — `GET /config`, `GET /availability?date=`, `POST /reserve` (public; protected by `wp_rest` nonce + `rar_hp` honeypot field), `POST /config` (`edit_pages`). The daily per-patron limit is checked before insert and re-checked after insert (rolling back by `booking_id`) to close the race window.

**Statistics + privacy** — patron data (name, email, token) may live only in the slots table, and only until a booking ends or is cancelled. `rar_retire_booking()` deletes the slot rows and writes one anonymous row to `{prefix}rar_stats` (kind `booked`/`cancelled`, reason, room id + name snapshot, start, length, created/cancelled times). `rar_purge_past()` retires ended bookings (hourly `rar_purge` cron, plus on admin page loads). `rar_log_denied()` records turned-away requests (`taken`, `daily`, `domain`). Never add identifying columns to the stats table. `rar_stats()` computes everything for the dashboard (`rar_stats_page`, submenu "Statistics") and the CSV export (`admin_post_rar_stats_csv`). Utilization uses open hours from `rar_candidate_slots()` × the current room count. `created_at` is library time (`rar_now()`), the same clock as `slot_start`.

**Other entry points** — cancellation via `?rar_cancel=<token>` on any front-end URL (handled in `template_redirect`, renders a standalone page); admin menu "Reserve a Room" (editor) + "Reservations" submenu (list with nonce-protected staff cancel). Confirmation emails use `RAR_REPLY_TO`. Allowed email domains (if set) are enforced in `rar_reserve()` and mirrored client-side.

**Front-end widget** — the 12h/24h toggle is display-only; all times sent to the server and stored are 24-hour `HH:MM`. Accessibility matters here (keyboard grid navigation, aria-labels, pa11y-clean): preserve it when touching widget markup.
