# wprooms — Reserve a Room

No-code WordPress plugin for study-room booking. Staff manage rooms and rules under
**Reserve a Room** in wp-admin; patrons book instantly with the `[reserve_a_room]`
shortcode. Bookable hours come from the [Library Hours](https://github.com/tfscharff/wphours)
plugin. Replaces LibCal Spaces.

- Per-room booking rules, including an optional daily booking limit per patron
- Optionally restrict bookings to one or more email domains
- Choose whether the booking grid shows 12-hour or 24-hour times by default
- Confirmation and cancellation emails
- Stores bookings in a dedicated database table
- Statistics dashboard for space and staffing planning, with CSV export
- Keeps no patron data once a reservation is over

## Statistics and privacy

**Reserve a Room → Statistics** shows how the rooms are used over any date range:

- A weekday × hour heatmap of how much open room-time was booked, marking hours when
  every room was booked at once
- Most used rooms, busiest times of day and days of the week
- How far ahead people book (median, average, and a breakdown), and how long they book for
- Cancellations: rate, how much notice was given, and whether the patron or staff cancelled
- Requests turned away because the time was taken, the daily limit was reached, or the
  email domain wasn't allowed
- Week-by-week hours and reservations

Two CSV downloads cover the same range: a **Summary** of every figure above, and the
anonymous **Records** behind them.

Patron names, emails and cancel links are deleted as soon as a reservation ends (checked
hourly) or is cancelled. Only an anonymous record is kept: room, date and time, length,
when it was booked, and whether it was kept, cancelled or turned away. "In use"
percentages compare booked time with open hours from Library Hours, for the rooms set up
now.

## Install

Download the latest release zip, then in wp-admin: **Plugins → Add New → Upload Plugin**.

### Upgrading from 1.x

2.0.0 renamed the plugin folder from `mcw-reserve-a-room` to `reserve-a-room`, so
WordPress sees it as a new plugin. Rooms, rules and bookings carry over automatically.

1. **Plugins** → deactivate the old **Reserve a Room**, then delete it (this does not
   delete rooms or bookings).
2. Upload `reserve-a-room.zip` and activate it.

## Changelog

This project uses [Semantic Versioning](https://semver.org/).

### 2.1.0
- New **Statistics** page: usage heatmap, rooms, busiest times and days, lead time,
  booking length, cancellations, requests turned away and weekly trend, for any date range.
- Download the statistics as CSV (summary or anonymous records).
- Patron data is no longer kept after a reservation ends: an hourly job deletes the name,
  email and cancel link and keeps only an anonymous record. Past reservations already in
  the database are converted the first time it runs.
- Failed booking attempts (time taken, daily limit, email domain) are counted.

### 2.0.0
- **Breaking:** renamed the plugin's folder and file from `mcw-reserve-a-room` to `reserve-a-room`,
  and dropped the "mcw" prefix from everything internal: settings, the bookings
  table, REST API (`/wp-json/reserve-a-room/v1/…`), admin page URLs, and CSS
  classes/ids. See **Upgrading from 1.x** above. Existing rooms, rules and
  bookings move over automatically on activation, and cancel links in
  confirmation emails sent by 1.x keep working.
- Author is now Thomas Scharff.

### 1.2.0
- New **Default time format** setting under Rules: choose whether the
  booking grid opens in 24-hour or 12-hour time. Patrons can still switch
  with the 12h/24h toggle.
- New **Allowed email domains** setting: list one or more domains (one per
  line or comma-separated) and only email addresses at those domains, or
  their subdomains, can book. Leave it empty to let anyone book. Checked on
  the server; the booking form also shows which domains are accepted and
  flags a non-matching address before submitting.

### 1.1.3
- The "i" beside a room name is now a button: click, tap, Enter or Space
  shows the room's note under its name, and Esc or another click hides it.
  The note used to be a hover-only tooltip, which didn't show in Firefox,
  on phones, or from the keyboard.

### 1.1.2
- The grid legend's green and grey swatches take their colors from the
  stylesheet instead of inline styles, and are hidden from screen readers
  (the text beside them already says what they mean). Clears two pa11y
  "inline background colour" warnings; looks the same.

### 1.1.1
- Drop "MCW" from the plugin's display name (now just "Reserve a Room").
  Cosmetic only; internal code identifiers, the option name, and the
  database table are unchanged.

### 1.1.0
- Add a 12-hour/24-hour time format toggle to the front-end booking widget.
  Display-only: switches how times are shown in the grid, aria-labels, and
  reservation form. Submitted data, stored bookings, and confirmation
  emails are unaffected and always use 24-hour time.

### 1.0.8
- Fix: honeypot anti-bot field falsely blocked reservations in Chrome/Edge.
  Chromium autofill matched the hidden field by its `name="website"` and
  silently filled it from saved profile data, tripping the bot check.
  Renamed the field and changed how it's hidden so autofill no longer
  targets it.

### 1.0.7
- Single-source `MCW_ROOMS_VERSION` from the plugin header instead of a
  hardcoded value that could drift.

## License

GPL-2.0+
