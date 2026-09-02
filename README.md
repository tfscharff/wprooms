# wprooms — MCW Reserve a Room

No-code WordPress plugin for study-room booking. Staff manage rooms and rules under
**Reserve a Room** in wp-admin; patrons book instantly with the `[reserve_a_room]`
shortcode. Bookable hours come from the [Library Hours](https://github.com/tfscharff/wphours)
plugin. Replaces LibCal Spaces.

- Per-room booking rules, including an optional daily booking limit per patron
- Confirmation and cancellation emails
- Stores bookings in a dedicated database table

## Install

Download the latest release zip, then in wp-admin: **Plugins → Add New → Upload Plugin**.

## Changelog

This project uses [Semantic Versioning](https://semver.org/).

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
