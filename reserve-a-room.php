<?php
/**
 * Plugin Name:       Reserve a Room
 * Description:        No-code study-room booking: staff manage rooms and rules under Reserve a Room in wp-admin; patrons book instantly with [reserve_a_room]. Bookable hours come from the Library Hours plugin. Replaces LibCal Spaces.
 * Version:           2.1.0
 * Author:            Thomas Scharff
 * License:           GPL-2.0+
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'RAR_OPTION', 'rar_rooms' );
// Version is single-sourced from the plugin header above (SemVer 2.0.0), read via
// get_file_data() so the constant can never drift from the canonical Version: field.
define( 'RAR_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );
define( 'RAR_DB_VERSION', '2' );
define( 'RAR_REPLY_TO', 'library@wheatoncollege.edu' );

function rar_table() {
	global $wpdb;
	return $wpdb->prefix . 'rar_slots';
}

register_activation_hook( __FILE__, 'rar_install' );

function rar_install() {
	global $wpdb;
	$table   = rar_table();
	$charset = $wpdb->get_charset_collate();
	// One row per occupied 30-min slot. UNIQUE(room_id, slot_start) makes double-booking impossible.
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id CHAR(32) NOT NULL,
		room_id VARCHAR(40) NOT NULL,
		slot_start DATETIME NOT NULL,
		first VARCHAR(80) NOT NULL,
		last VARCHAR(80) NOT NULL,
		email VARCHAR(190) NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY room_slot (room_id, slot_start),
		KEY booking_id (booking_id),
		KEY slot_start (slot_start)
	) {$charset};";
	// Anonymous statistics: one row per finished, cancelled or turned-away reservation.
	// Deliberately has no name, email or token column — patron data never lands here.
	$stats     = rar_stats_table();
	$sql_stats = "CREATE TABLE {$stats} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		kind VARCHAR(12) NOT NULL,
		reason VARCHAR(12) NOT NULL DEFAULT '',
		room_id VARCHAR(40) NOT NULL DEFAULT '',
		room_name VARCHAR(190) NOT NULL DEFAULT '',
		start_at DATETIME NULL,
		duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		cancelled_at DATETIME NULL,
		PRIMARY KEY  (id),
		KEY start_at (start_at),
		KEY created_at (created_at)
	) {$charset};";
	rar_migrate_legacy();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( array( $sql, $sql_stats ) );
	update_option( 'rar_db_version', RAR_DB_VERSION );
}

/**
 * One-time move of data saved by versions before 2.0.0, which used an "mcw" prefix
 * (mcw_rooms option, {prefix}mcw_room_slots table). Safe to run repeatedly.
 */
function rar_migrate_legacy() {
	global $wpdb;
	$old = $wpdb->prefix . 'mcw_room_slots';
	$new = rar_table();
	if ( $old === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) ) {
		if ( $new !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new ) ) ) ) {
			$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$cols = 'booking_id, room_id, slot_start, first, last, email, created_at';
			// phpcs:ignore WordPress.DB.PreparedSQL
			if ( false !== $wpdb->query( "INSERT IGNORE INTO `{$new}` ({$cols}) SELECT {$cols} FROM `{$old}`" ) ) {
				$wpdb->query( "DROP TABLE `{$old}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
	}
	$rooms = get_option( 'mcw_rooms' );
	if ( false !== $rooms ) {
		if ( false === get_option( RAR_OPTION ) ) { add_option( RAR_OPTION, $rooms ); }
		delete_option( 'mcw_rooms' );
	}
	delete_option( 'mcw_rooms_db_version' );
}

// Also run install/migration on load when the stored schema version is behind,
// since the activation hook doesn't fire for every way a plugin gets updated.
add_action( 'plugins_loaded', function () {
	if ( get_option( 'rar_db_version' ) !== RAR_DB_VERSION ) { rar_install(); }
} );

/* ------------------------------------------------------------------ *
 *  Rooms + rules config
 * ------------------------------------------------------------------ */

function rar_defaults() {
	return array(
		'rules' => array( 'slotMin' => 30, 'maxMin' => 120, 'advanceDays' => 14, 'dailyMaxMin' => 0, 'timeFormat' => '24', 'emailDomains' => array() ),
		'rooms' => array(
			array( 'id' => 'r1', 'name' => 'Study Room 1', 'capacity' => 4, 'notes' => '' ),
			array( 'id' => 'r2', 'name' => 'Study Room 2', 'capacity' => 4, 'notes' => '' ),
		),
	);
}

function rar_get() {
	$v = get_option( RAR_OPTION );
	if ( empty( $v ) || ! is_array( $v ) ) { $v = rar_defaults(); }
	if ( ! isset( $v['rules'] ) || ! is_array( $v['rules'] ) ) {
		$d           = rar_defaults();
		$v['rules']  = $d['rules'];
	}
	if ( ! isset( $v['rooms'] ) || ! is_array( $v['rooms'] ) ) { $v['rooms'] = array(); }
	// Fill in rules added after the option was first stored.
	$d = rar_defaults();
	foreach ( $d['rules'] as $k => $def ) {
		if ( ! isset( $v['rules'][ $k ] ) ) { $v['rules'][ $k ] = $def; }
	}
	return $v;
}

/** Generate a stable, unique room id. Must be collision-proof even when many rooms are saved at once. */
function rar_new_id() {
	if ( function_exists( 'random_bytes' ) ) {
		return 'r' . bin2hex( random_bytes( 6 ) );
	}
	return 'r' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
}

function rar_sanitize( $in ) {
	$out = array( 'rules' => array(), 'rooms' => array() );
	$r   = isset( $in['rules'] ) && is_array( $in['rules'] ) ? $in['rules'] : array();
	$out['rules']['slotMin']     = 30; // fixed grid granularity
	$out['rules']['maxMin']      = min( 480, max( 30, isset( $r['maxMin'] ) ? (int) $r['maxMin'] : 120 ) );
	$out['rules']['advanceDays'] = min( 90, max( 1, isset( $r['advanceDays'] ) ? (int) $r['advanceDays'] : 14 ) );
	// maxMin must be a multiple of 30
	$out['rules']['maxMin'] = (int) ( round( $out['rules']['maxMin'] / 30 ) * 30 );
	if ( $out['rules']['maxMin'] < 30 ) { $out['rules']['maxMin'] = 30; }
	// Daily limit per person, in minutes. 0 = no limit. Otherwise a multiple of 30, 30–480,
	// and never below the per-booking max (a lower cap would make some bookings impossible).
	$daily = isset( $r['dailyMaxMin'] ) ? (int) $r['dailyMaxMin'] : 0;
	if ( $daily > 0 ) {
		$daily = min( 480, max( 30, $daily ) );
		$daily = (int) ( round( $daily / 30 ) * 30 );
		if ( $daily < $out['rules']['maxMin'] ) { $daily = $out['rules']['maxMin']; }
	} else {
		$daily = 0;
	}
	$out['rules']['dailyMaxMin'] = $daily;
	// Default time display in the booking widget: '24' or '12'. Patrons can still toggle.
	$out['rules']['timeFormat'] = ( isset( $r['timeFormat'] ) && '12' === (string) $r['timeFormat'] ) ? '12' : '24';
	// Allowed email domains (empty = anyone). Accepts an array or a comma/space/newline-separated string.
	$out['rules']['emailDomains'] = rar_sanitize_domains( isset( $r['emailDomains'] ) ? $r['emailDomains'] : array() );

	$rooms = isset( $in['rooms'] ) && is_array( $in['rooms'] ) ? $in['rooms'] : array();
	$used  = array();
	foreach ( $rooms as $room ) {
		if ( ! is_array( $room ) ) { continue; }
		$name = isset( $room['name'] ) ? sanitize_text_field( $room['name'] ) : '';
		if ( '' === $name ) { continue; }
		$id = isset( $room['id'] ) ? preg_replace( '/[^a-z0-9]/', '', (string) $room['id'] ) : '';
		// Assign a fresh id when missing OR when it collides with another room in this batch
		// (self-heals rooms that were saved with a duplicate id by an earlier version).
		if ( '' === $id || isset( $used[ $id ] ) ) {
			do { $id = rar_new_id(); } while ( isset( $used[ $id ] ) );
		}
		$used[ $id ] = true;
		$out['rooms'][] = array(
			'id'       => $id,
			'name'     => $name,
			'capacity' => isset( $room['capacity'] ) ? max( 0, (int) $room['capacity'] ) : 0,
			'notes'    => isset( $room['notes'] ) ? sanitize_text_field( $room['notes'] ) : '',
		);
	}
	return $out;
}

/** Normalize a list of email domains: lowercase, strip a leading "@", drop invalid entries and duplicates. */
function rar_sanitize_domains( $in ) {
	$list = is_array( $in ) ? $in : preg_split( '/[\s,;]+/', (string) $in );
	$out  = array();
	foreach ( $list as $d ) {
		$d = ltrim( strtolower( trim( (string) $d ) ), '@' );
		if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $d ) && ! in_array( $d, $out, true ) ) {
			$out[] = $d;
		}
	}
	return $out;
}

/** True if $email's domain is one of $domains or a subdomain of one. An empty list allows any email. */
function rar_email_domain_allowed( $email, $domains ) {
	if ( empty( $domains ) ) { return true; }
	$at = strrpos( (string) $email, '@' );
	if ( false === $at ) { return false; }
	$host = strtolower( substr( $email, $at + 1 ) );
	foreach ( $domains as $d ) {
		if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) { return true; }
	}
	return false;
}

/** "@a.edu", "@a.edu or @b.edu", "@a.edu, @b.edu, or @c.edu" */
function rar_domains_phrase( $domains ) {
	$d = array_map( function ( $x ) { return '@' . $x; }, $domains );
	if ( count( $d ) < 3 ) { return implode( ' or ', $d ); }
	$last = array_pop( $d );
	return implode( ', ', $d ) . ', or ' . $last;
}

/* ------------------------------------------------------------------ *
 *  Time / hours / slot helpers
 * ------------------------------------------------------------------ */

function rar_hhmm2min( $t ) {
	$a = explode( ':', (string) $t );
	return ( (int) $a[0] ) * 60 + ( isset( $a[1] ) ? (int) $a[1] : 0 );
}
function rar_min2hhmm( $m ) {
	$m = (int) $m;
	return sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
}

function rar_hours_option() {
	$h = get_option( 'mcw_library_hours' );
	return is_array( $h ) ? $h : null;
}

function rar_tz() {
	$h  = rar_hours_option();
	$tz = ( $h && ! empty( $h['timezone'] ) ) ? $h['timezone'] : 'America/New_York';
	return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'America/New_York';
}

function rar_now() {
	return new DateTime( 'now', new DateTimeZone( rar_tz() ) );
}

/** Keep only well-formed {open,close} blocks from a day's list. */
function rar_normalize_blocks( $blocks ) {
	$out = array();
	if ( ! is_array( $blocks ) ) { return $out; }
	foreach ( $blocks as $b ) {
		if ( is_array( $b ) && isset( $b['open'], $b['close'] ) ) {
			$out[] = array( 'open' => $b['open'], 'close' => $b['close'] );
		}
	}
	return $out;
}

/**
 * Open blocks for a date, mirroring the hours plugin's resolution precedence:
 * single-date exception > term range > weekly default. Reads only the regular
 * (building) schedule — guest hours are intentionally ignored for room booking.
 * Returns [] if the hours plugin is missing or the day is closed.
 */
function rar_open_blocks( $date ) {
	$h = rar_hours_option();
	if ( ! $h ) { return array(); }

	// 1. A single-date exception overrides everything.
	$exc = isset( $h['exceptions'] ) && is_array( $h['exceptions'] ) ? $h['exceptions'] : array();
	if ( isset( $exc[ $date ] ) && is_array( $exc[ $date ] ) ) {
		$e = $exc[ $date ];
		if ( isset( $e['closed'] ) && $e['closed'] ) { return array(); }
		return rar_normalize_blocks( $e );
	}

	$dow = (string) (int) ( new DateTime( $date, new DateTimeZone( rar_tz() ) ) )->format( 'w' );

	// 2. The first term range covering this date overrides the weekly default.
	$ranges = isset( $h['ranges'] ) && is_array( $h['ranges'] ) ? $h['ranges'] : array();
	foreach ( $ranges as $r ) {
		if ( ! is_array( $r ) || empty( $r['start'] ) || empty( $r['end'] ) ) { continue; }
		if ( $date >= $r['start'] && $date <= $r['end'] ) {
			$rw = isset( $r['weekly'] ) && is_array( $r['weekly'] ) ? $r['weekly'] : array();
			return rar_normalize_blocks( isset( $rw[ $dow ] ) ? $rw[ $dow ] : array() );
		}
	}

	// 3. Weekly default.
	$weekly = isset( $h['weekly'] ) && is_array( $h['weekly'] ) ? $h['weekly'] : array();
	return rar_normalize_blocks( isset( $weekly[ $dow ] ) ? $weekly[ $dow ] : array() );
}

/**
 * Candidate 30-min slot starts ('HH:MM') that fit fully inside an open block.
 * A slot occupies [start, start+30); the last slot in a block ends exactly at close.
 */
function rar_candidate_slots( $date ) {
	$slots = array();
	foreach ( rar_open_blocks( $date ) as $b ) {
		$o = rar_hhmm2min( $b['open'] );
		$c = rar_hhmm2min( $b['close'] );          // "24:00" => 1440
		for ( $m = $o; $m + 30 <= $c; $m += 30 ) {
			$slots[] = rar_min2hhmm( $m );
		}
	}
	$slots = array_values( array_unique( $slots ) );
	sort( $slots );
	return $slots;
}

/* ------------------------------------------------------------------ *
 *  REST: read (config + availability)
 * ------------------------------------------------------------------ */

/** Map of room_id => list of taken 'HH:MM' starts on a date. */
function rar_taken_for_date( $date ) {
	global $wpdb;
	$table = rar_table();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT room_id, DATE_FORMAT(slot_start, '%%H:%%i') AS hhmm FROM {$table} WHERE DATE(slot_start) = %s",
		$date
	) );
	$out = array();
	if ( $rows ) {
		foreach ( $rows as $r ) {
			if ( ! isset( $out[ $r->room_id ] ) ) { $out[ $r->room_id ] = array(); }
			$out[ $r->room_id ][] = $r->hhmm;
		}
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'reserve-a-room/v1', '/config', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$cfg = rar_get();
			return rest_ensure_response( array(
				'rules'      => $cfg['rules'],
				'rooms'      => array_map( function ( $r ) {
					return array( 'id' => $r['id'], 'name' => $r['name'], 'capacity' => $r['capacity'], 'notes' => $r['notes'] );
				}, $cfg['rooms'] ),
				'hoursReady' => (bool) rar_hours_option(),
			) );
		},
	) );

	register_rest_route( 'reserve-a-room/v1', '/availability', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'date' => array( 'required' => true ) ),
		'callback'            => 'rar_availability',
	) );

	register_rest_route( 'reserve-a-room/v1', '/reserve', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // patrons are not logged in; hardened in the handler
		'callback'            => 'rar_reserve',
	) );

	register_rest_route( 'reserve-a-room/v1', '/config', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
		'callback'            => function ( WP_REST_Request $req ) {
			$body = $req->get_json_params();
			if ( ! is_array( $body ) || ! isset( $body['rooms'] ) ) {
				return new WP_Error( 'rar_bad', 'Invalid data.', array( 'status' => 400 ) );
			}
			$clean = rar_sanitize( $body );
			update_option( RAR_OPTION, $clean );
			// Echo the sanitized rules back so the editor shows what was actually stored.
			return rest_ensure_response( array( 'ok' => true, 'rules' => $clean['rules'] ) );
		},
	) );
} );

function rar_availability( WP_REST_Request $req ) {
	$date = (string) $req->get_param( 'date' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return new WP_Error( 'rar_date', 'Invalid date.', array( 'status' => 400 ) );
	}
	if ( ! rar_hours_option() ) {
		return new WP_Error( 'rar_hours', 'Room booking is temporarily unavailable.', array( 'status' => 503 ) );
	}
	$cfg   = rar_get();
	$now   = rar_now();
	$today = $now->format( 'Y-m-d' );
	$max   = ( clone $now )->modify( '+' . (int) $cfg['rules']['advanceDays'] . ' days' )->format( 'Y-m-d' );
	if ( $date < $today || $date > $max ) {
		return new WP_Error( 'rar_window', 'That date is outside the booking window.', array( 'status' => 400 ) );
	}
	$nowMin = ( $date === $today ) ? ( (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' ) ) : null;
	return rest_ensure_response( array(
		'date'    => $date,
		'blocks'  => rar_open_blocks( $date ),
		'slots'   => rar_candidate_slots( $date ),
		'slotMin' => (int) $cfg['rules']['slotMin'],
		'maxMin'  => (int) $cfg['rules']['maxMin'],
		'dailyMaxMin' => (int) $cfg['rules']['dailyMaxMin'],
		'rooms'   => array_map( function ( $r ) { return array( 'id' => $r['id'], 'name' => $r['name'], 'notes' => $r['notes'], 'capacity' => $r['capacity'] ); }, $cfg['rooms'] ),
		'taken'   => rar_taken_for_date( $date ),
		'nowMin'  => $nowMin,
	) );
}

/* ------------------------------------------------------------------ *
 *  REST: reserve (atomic, double-booking-safe)
 * ------------------------------------------------------------------ */

/** Minutes this email already has booked on $date, across all rooms. One row = 30 minutes. */
function rar_booked_minutes_on( $email, $date ) {
	global $wpdb;
	$table = rar_table();
	// Half-open range [date 00:00, next day 00:00). Built with DateTime so the bound never depends
	// on the server's default timezone.
	$next = date_create_immutable( $date . ' 00:00:00' );
	$next = $next ? $next->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) : $date . ' 23:59:59';
	$slots = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE LOWER(email) = LOWER(%s) AND slot_start >= %s AND slot_start < %s", // phpcs:ignore WordPress.DB.PreparedSQL
		$email,
		$date . ' 00:00:00',
		$next
	) );
	return $slots * 30;
}

/** Human phrasing for a minutes value: "2 hours", "90 minutes", "30 minutes". */
function rar_fmt_minutes( $min ) {
	$min = (int) $min;
	if ( $min > 0 && 0 === $min % 60 ) {
		$h = intdiv( $min, 60 );
		return $h . ( 1 === $h ? ' hour' : ' hours' );
	}
	return $min . ' minutes';
}

function rar_daily_limit_message( $limit, $already ) {
	$left = max( 0, (int) $limit - (int) $already );
	$msg  = 'There’s a limit of ' . rar_fmt_minutes( $limit ) . ' per person per day, and you already have '
		. rar_fmt_minutes( $already ) . ' booked for that date.';
	$msg .= $left > 0
		? ' You can still book up to ' . rar_fmt_minutes( $left ) . '.'
		: ' Please choose another date, or cancel an existing reservation first.';
	return $msg;
}

function rar_reserve( WP_REST_Request $req ) {
	global $wpdb;

	// 1. Anti-bot.
	if ( ! wp_verify_nonce( $req->get_param( '_wpnonce' ), 'wp_rest' ) ) {
		return new WP_Error( 'rar_nonce', 'Your session expired. Please reload the page and try again.', array( 'status' => 403 ) );
	}
	if ( '' !== trim( (string) $req->get_param( 'rar_hp' ) ) ) {
		return new WP_Error( 'rar_bot', 'Submission blocked.', array( 'status' => 400 ) );
	}

	// 2. Inputs.
	$room_id  = preg_replace( '/[^a-z0-9]/', '', (string) $req->get_param( 'room_id' ) );
	$date     = (string) $req->get_param( 'date' );
	$start    = (string) $req->get_param( 'start' );     // 'HH:MM'
	$duration = (int) $req->get_param( 'duration' );      // minutes
	$first    = sanitize_text_field( (string) $req->get_param( 'first' ) );
	$last     = sanitize_text_field( (string) $req->get_param( 'last' ) );
	$email    = sanitize_email( (string) $req->get_param( 'email' ) );

	if ( '' === $first || '' === $last ) {
		return new WP_Error( 'rar_name', 'Please enter your first and last name.', array( 'status' => 400 ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'rar_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
	}
	$domains = rar_get()['rules']['emailDomains'];
	if ( ! rar_email_domain_allowed( $email, $domains ) ) {
		rar_log_denied( 'domain', $room_id, $date, $start, $duration );
		return new WP_Error( 'rar_email_domain', 'Reservations are limited to ' . rar_domains_phrase( $domains ) . ' email addresses.', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) {
		return new WP_Error( 'rar_input', 'Invalid reservation details.', array( 'status' => 400 ) );
	}

	// 3. Room + rules.
	$cfg  = rar_get();
	$room = null;
	foreach ( $cfg['rooms'] as $r ) { if ( $r['id'] === $room_id ) { $room = $r; break; } }
	if ( ! $room ) {
		return new WP_Error( 'rar_room', 'That room is not available.', array( 'status' => 400 ) );
	}
	$maxMin = (int) $cfg['rules']['maxMin'];
	if ( $duration < 30 || $duration > $maxMin || 0 !== $duration % 30 ) {
		return new WP_Error( 'rar_dur', 'Please choose a valid length.', array( 'status' => 400 ) );
	}

	// 4. Date within window.
	$now   = rar_now();
	$today = $now->format( 'Y-m-d' );
	$max   = ( clone $now )->modify( '+' . (int) $cfg['rules']['advanceDays'] . ' days' )->format( 'Y-m-d' );
	if ( $date < $today || $date > $max ) {
		return new WP_Error( 'rar_window', 'That date is outside the booking window.', array( 'status' => 400 ) );
	}

	// 5. Every needed slot must be an in-hours candidate (guarantees within hours + no gap-spanning),
	//    and not in the past.
	$candidates = rar_candidate_slots( $date );
	$startMin   = rar_hhmm2min( $start );
	$nowMin     = ( (int) $now->format( 'G' ) ) * 60 + (int) $now->format( 'i' );
	$needed     = array();
	for ( $m = $startMin; $m < $startMin + $duration; $m += 30 ) {
		$hhmm = rar_min2hhmm( $m );
		if ( ! in_array( $hhmm, $candidates, true ) ) {
			return new WP_Error( 'rar_hours', 'That time is outside the room’s available hours.', array( 'status' => 400 ) );
		}
		if ( $date === $today && $m < $nowMin ) {
			return new WP_Error( 'rar_past', 'That time has already passed.', array( 'status' => 400 ) );
		}
		$needed[] = $hhmm;
	}

	// 5b. Daily limit per person (0 = no limit). Counted across all rooms for this email on this date.
	$dailyMax = (int) $cfg['rules']['dailyMaxMin'];
	if ( $dailyMax > 0 ) {
		$already = rar_booked_minutes_on( $email, $date );
		if ( $already + $duration > $dailyMax ) {
			rar_log_denied( 'daily', $room_id, $date, $start, $duration );
			return new WP_Error(
				'rar_daily',
				rar_daily_limit_message( $dailyMax, $already ),
				array( 'status' => 400 )
			);
		}
	}

	// 6. Atomic multi-row insert. UNIQUE(room_id, slot_start) blocks any conflict, even concurrent ones.
	$token   = wp_generate_password( 32, false );
	$created = rar_now()->format( 'Y-m-d H:i:s' ); // library time, same clock as slot_start, so lead time is exact
	$table   = rar_table();
	$tuples  = array();
	$args    = array();
	foreach ( $needed as $hhmm ) {
		$tuples[] = '(%s,%s,%s,%s,%s,%s,%s)';
		$args[]   = $token;
		$args[]   = $room_id;
		$args[]   = $date . ' ' . $hhmm . ':00';
		$args[]   = $first;
		$args[]   = $last;
		$args[]   = $email;
		$args[]   = $created;
	}
	$sql = "INSERT INTO {$table} (booking_id, room_id, slot_start, first, last, email, created_at) VALUES " . implode( ',', $tuples );
	$res = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	if ( false === $res ) {
		if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
			rar_log_denied( 'taken', $room_id, $date, $start, $duration );
			return new WP_Error( 'rar_taken', 'Sorry, that time was just booked. Please pick another slot.', array( 'status' => 409 ) );
		}
		return new WP_Error( 'rar_db', 'Something went wrong saving your reservation. Please try again.', array( 'status' => 500 ) );
	}

	// 6b. Re-check the daily limit now that the rows exist, so two simultaneous requests can't both
	//     squeak past the pre-check. If this booking put the person over, undo it.
	if ( $dailyMax > 0 ) {
		$total = rar_booked_minutes_on( $email, $date );
		if ( $total > $dailyMax ) {
			$wpdb->delete( $table, array( 'booking_id' => $token ), array( '%s' ) );
			rar_log_denied( 'daily', $room_id, $date, $start, $duration );
			return new WP_Error(
				'rar_daily',
				rar_daily_limit_message( $dailyMax, $total - $duration ),
				array( 'status' => 400 )
			);
		}
	}

	// 7. Confirmation email (best-effort).
	$endHHMM = rar_min2hhmm( $startMin + $duration );
	rar_email_confirm( $email, $room['name'], $date, $start, $endHHMM, $token );

	return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
}

function rar_email_confirm( $to, $room_name, $date, $startHHMM, $endHHMM, $token ) {
	$cancel  = add_query_arg( 'rar_cancel', $token, home_url( '/' ) );
	$subject = 'Room reservation confirmed — ' . $room_name . ' on ' . $date;
	$lines   = array(
		'Your study-room reservation is confirmed:',
		'',
		'Room: ' . $room_name,
		'Date: ' . $date,
		'Time: ' . $startHHMM . ' – ' . $endHHMM,
		'',
		'Need to cancel? Use this link:',
		$cancel,
	);
	$headers = array( 'Reply-To: ' . RAR_REPLY_TO );
	if ( ! wp_mail( $to, $subject, implode( "\n", $lines ), $headers ) ) {
		error_log( 'reserve-a-room: confirmation email failed for ' . $to );
	}
}

/* ------------------------------------------------------------------ *
 *  Cancellation (tokenized link from the confirmation email)
 * ------------------------------------------------------------------ */

/** Cancel a booking by token. $by ('patron' or 'staff') is kept, anonymously, for statistics. */
function rar_cancel_booking( $token, $by = 'patron' ) {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( 32 !== strlen( $token ) ) { return 0; }
	return rar_retire_booking( $token, 'cancelled', $by );
}

add_action( 'template_redirect', function () {
	// mcw_cancel: links in confirmation emails sent before 2.0.0 must keep working.
	$param = isset( $_GET['rar_cancel'] ) ? 'rar_cancel' : ( isset( $_GET['mcw_cancel'] ) ? 'mcw_cancel' : '' ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $param ) { return; }
	$deleted = rar_cancel_booking( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security
	$ok  = $deleted > 0;
	$msg = $ok
		? 'Your reservation has been cancelled. The room is now free for others to book.'
		: 'This reservation was already cancelled, or the link is invalid.';
	$home = esc_url( home_url( '/' ) );
	status_header( 200 );
	nocache_headers();
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Reservation cancellation</title>'
		. '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;'
		. 'background:#f4f4f4;margin:0;color:#1a1a1a}.box{max-width:520px;margin:12vh auto;background:#fff;'
		. 'border-radius:12px;padding:2rem 2.25rem;box-shadow:0 1px 3px rgba(0,0,0,.12)}h1{color:#00539b;font-size:1.3rem}'
		. 'a{color:#00539b}</style></head><body><div class="box"><h1>'
		. ( $ok ? 'Reservation cancelled' : 'Nothing to cancel' ) . '</h1><p>' . esc_html( $msg ) . '</p>'
		. '<p><a href="' . $home . '">Return to the library website</a></p></div></body></html>';
	exit;
} );

/* ------------------------------------------------------------------ *
 *  Admin: rooms/rules editor + reservations list
 * ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_menu_page(
		'Reserve a Room', 'Reserve a Room', 'edit_pages',
		'reserve-a-room', 'rar_admin_page', 'dashicons-calendar-alt', 32
	);
	// After the editor so the editor stays the default submenu (Piece 2 menu-order lesson).
	add_submenu_page(
		'reserve-a-room', 'Reservations', 'Reservations', 'edit_pages',
		'reserve-a-room-reservations', 'rar_reservations_page'
	);
	add_submenu_page(
		'reserve-a-room', 'Room statistics', 'Statistics', 'edit_pages',
		'reserve-a-room-stats', 'rar_stats_page'
	);
} );

function rar_admin_page() {
	$root  = esc_url_raw( rest_url( 'reserve-a-room/v1/config' ) );
	$nonce = wp_create_nonce( 'wp_rest' );
	echo '<div class="wrap"><h1>Reserve a Room</h1>';
	echo '<p style="max-width:680px;color:#555">Add each bookable room and set the rules, then click <strong>Save &amp; publish</strong>. Bookable hours come automatically from <strong>Library Hours</strong> — rooms can only be booked when the library is open. Show the booking grid on any page with <code>[reserve_a_room]</code>.</p>';
	echo '<script>window.RAR_ADMIN=' . wp_json_encode( array( 'root' => $root, 'nonce' => $nonce ) ) . ';</script>';
	echo rar_editor_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

/** The editor UI + JS. Nowdoc => no PHP interpolation of $ in JS. */
function rar_editor_markup() {
	return <<<'HTML'
<style>
  #rar-admin{--accent:#00539b;--line:#dcdcdc;--muted:#666;max-width:820px;font-size:14px}
  #rar-admin .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:14px 0;position:relative}
  #rar-admin .grid{display:grid;grid-template-columns:2fr 1fr;gap:10px 14px}
  #rar-admin .full{grid-column:1/3}
  #rar-admin label{display:block;font-weight:600;font-size:.82rem;color:var(--muted);margin-bottom:3px}
  #rar-admin input[type=text],#rar-admin input[type=number]{width:100%;font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;box-sizing:border-box}
  #rar-admin .num{font-weight:700;color:var(--accent);margin-bottom:6px}
  #rar-admin .b{cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff;padding:8px 14px;border-radius:7px;font-weight:600;font-size:.9rem}
  #rar-admin .b.sec{background:#fff;color:var(--accent)}
  #rar-admin .b.ghost{background:#fff;color:var(--muted);border-color:var(--line)}
  #rar-admin .b.tiny{padding:3px 9px;font-size:.8rem;border-radius:5px;position:absolute;top:12px;right:14px}
  #rar-admin .rules{display:flex;gap:16px;flex-wrap:wrap;align-items:end}
  #rar-admin .rules label{font-weight:600;color:#1a1a1a}
  #rar-admin .rules input{width:90px}
  #rar-admin select{font:inherit;padding:5px 8px;border:1px solid var(--line);border-radius:6px}
  #rar-admin .domains{margin-top:14px}
  #rar-admin .domains label{color:#1a1a1a}
  #rar-admin textarea{width:100%;max-width:420px;font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;box-sizing:border-box}
  #rar-admin .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
  #rar-admin .status{font-weight:600}
  #rar-admin .hint{font-size:.82rem;color:var(--muted);margin:6px 0 0}
</style>
<div id="rar-admin">
  <div class="card">
    <h2>Rules</h2>
    <div class="rules">
      <label>Max length (minutes)<br><input type="number" id="rar-admin-max" min="30" step="30"></label>
      <label>Book ahead (days)<br><input type="number" id="rar-admin-adv" min="1" max="90"></label>
      <label>Daily limit per person (minutes)<br><input type="number" id="rar-admin-daily" min="0" max="480" step="30"></label>
      <label>Default time format<br><select id="rar-admin-fmt"><option value="24">24-hour (14:30)</option><option value="12">12-hour (2:30 PM)</option></select></label>
    </div>
    <p class="hint">Slots are 30 minutes. Max length must be a multiple of 30.</p>
    <p class="hint"><strong>Daily limit</strong> is the total a single person (by email) can book across
      all rooms on one day. Enter <strong>0</strong> for no limit. It can't be lower than the max length
      above — if you enter less, it's raised to match.</p>
    <p class="hint"><strong>Default time format</strong> is how times first appear in the booking grid.
      Patrons can still switch between 12h and 24h.</p>
    <div class="domains">
      <label for="rar-admin-domains">Allowed email domains</label>
      <textarea id="rar-admin-domains" rows="3" placeholder="wheatoncollege.edu" aria-describedby="rar-admin-domains-hint"></textarea>
      <p class="hint" id="rar-admin-domains-hint">One per line (or separated by commas). Only email addresses at these domains — or their
        subdomains — can book. Leave empty to let anyone book.</p>
    </div>
  </div>
  <div id="rar-admin-rooms"></div>
  <div class="bar"><button type="button" class="b sec" id="rar-admin-add">+ Add a room</button></div>
  <div class="bar"><button type="button" class="b" id="rar-admin-save">Save &amp; publish</button><span class="status" id="rar-admin-status"></span></div>
</div>
<script>
(function(){
"use strict";
var data={rules:{slotMin:30,maxMin:120,advanceDays:14,dailyMaxMin:0,timeFormat:"24",emailDomains:[]},rooms:[]};
function el(t,c){var e=document.createElement(t);if(c)e.className=c;return e;}
function renderRooms(){
  var wrap=document.getElementById("rar-admin-rooms");wrap.innerHTML="";
  if(data.rooms.length===0){var p=el("p","hint");p.textContent="No rooms yet — click “Add a room.”";wrap.appendChild(p);}
  data.rooms.forEach(function(room,i){
    var card=el("div","card");
    var num=el("div","num");num.textContent="Room "+(i+1);card.appendChild(num);
    var rm=el("button","b ghost tiny");rm.type="button";rm.textContent="remove";rm.onclick=function(){if(confirm("Remove this room? Its upcoming reservations will be cancelled when you save.")){data.rooms.splice(i,1);renderRooms();}};card.appendChild(rm);
    var grid=el("div","grid");
    grid.appendChild(field("Name",room,"name","text","full"));
    grid.appendChild(field("Capacity",room,"capacity","number",""));
    grid.appendChild(field("Notes (optional)",room,"notes","text","full"));
    card.appendChild(grid);wrap.appendChild(card);
  });
}
function field(label,obj,key,type,cls){
  var cell=el("div",cls);var lab=el("label");lab.textContent=label;
  var inp=el("input");inp.type=type;inp.value=obj[key]!=null?obj[key]:"";
  inp.oninput=function(){obj[key]=type==="number"?parseInt(inp.value||"0",10):inp.value;};
  cell.appendChild(lab);cell.appendChild(inp);return cell;
}
function fillRules(){
  document.getElementById("rar-admin-max").value=data.rules.maxMin;
  document.getElementById("rar-admin-adv").value=data.rules.advanceDays;
  document.getElementById("rar-admin-daily").value=data.rules.dailyMaxMin||0;
  document.getElementById("rar-admin-fmt").value=data.rules.timeFormat==="12"?"12":"24";
  document.getElementById("rar-admin-domains").value=(data.rules.emailDomains||[]).join("\n");
}
function flash(m,bad){var s=document.getElementById("rar-admin-status");s.textContent=m;s.style.color=bad?"#b42318":"#1a7f37";}
document.getElementById("rar-admin-add").onclick=function(){data.rooms.push({id:"",name:"",capacity:4,notes:""});renderRooms();};
document.getElementById("rar-admin-save").onclick=function(){
  data.rules.maxMin=parseInt(document.getElementById("rar-admin-max").value||"120",10);
  data.rules.advanceDays=parseInt(document.getElementById("rar-admin-adv").value||"14",10);
  data.rules.dailyMaxMin=parseInt(document.getElementById("rar-admin-daily").value||"0",10);
  if(!(data.rules.dailyMaxMin>0))data.rules.dailyMaxMin=0;
  data.rules.timeFormat=document.getElementById("rar-admin-fmt").value;
  data.rules.emailDomains=document.getElementById("rar-admin-domains").value.split(/[\s,;]+/).filter(Boolean);
  flash("Saving…");
  fetch(RAR_ADMIN.root,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":RAR_ADMIN.nonce},body:JSON.stringify(data)})
    .then(function(r){if(!r.ok)throw 0;return r.json();})
    .then(function(d){if(d&&d.rules){data.rules=d.rules;fillRules();}flash("Saved — it's live. ✓");})
    .catch(function(){flash("Save failed — please try again or reload.",true);});
};
fetch(RAR_ADMIN.root).then(function(r){return r.json();}).then(function(d){
  data.rooms=(d.rooms||[]).map(function(x){return{id:x.id,name:x.name,capacity:x.capacity,notes:x.notes};});
  data.rules=d.rules||data.rules;
  fillRules();
  renderRooms();
}).catch(function(){renderRooms();});
})();
</script>
HTML;
}

function rar_reservations_page() {
	global $wpdb;
	// Handle a staff cancel (nonce-protected form POST).
	if ( isset( $_POST['rar_cancel_token'], $_POST['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'rar_cancel' )
		&& current_user_can( 'edit_pages' ) ) {
		$n = rar_cancel_booking( sanitize_text_field( wp_unslash( $_POST['rar_cancel_token'] ) ), 'staff' );
		echo '<div class="notice notice-success"><p>' . ( $n > 0 ? 'Reservation cancelled.' : 'Nothing to cancel.' ) . '</p></div>';
	}
	rar_purge_past();
	$table = rar_table();
	$now   = rar_now()->format( 'Y-m-d H:i:s' );
	// One row per booking: min/max slot = start/end, +30 min on the last slot for the true end.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT booking_id, room_id, first, last, email,
		        MIN(slot_start) AS start_at, MAX(slot_start) AS last_slot, COUNT(*) AS slots
		 FROM {$table} WHERE slot_start >= %s
		 GROUP BY booking_id, room_id, first, last, email
		 ORDER BY start_at ASC", $now
	) );
	$cfg   = rar_get();
	$names = array();
	foreach ( $cfg['rooms'] as $r ) { $names[ $r['id'] ] = $r['name']; }

	echo '<div class="wrap"><h1>Upcoming Reservations</h1>';
	if ( ! $rows ) {
		echo '<p>No upcoming reservations.</p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Room</th><th>Date</th><th>Time</th><th>Patron</th><th>Email</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$room  = isset( $names[ $r->room_id ] ) ? $names[ $r->room_id ] : '(removed room)';
		$start = substr( $r->start_at, 11, 5 );
		$end   = gmdate( 'H:i', strtotime( $r->last_slot ) + 30 * 60 );
		$date  = substr( $r->start_at, 0, 10 );
		echo '<tr><td>' . esc_html( $room ) . '</td><td>' . esc_html( $date ) . '</td><td>' . esc_html( $start . ' – ' . $end )
			. '</td><td>' . esc_html( $r->first . ' ' . $r->last ) . '</td><td>' . esc_html( $r->email ) . '</td><td>'
			. '<form method="post" onsubmit="return confirm(\'Cancel this reservation?\')" style="margin:0">'
			. wp_nonce_field( 'rar_cancel', '_wpnonce', true, false )
			. '<input type="hidden" name="rar_cancel_token" value="' . esc_attr( $r->booking_id ) . '">'
			. '<button class="button button-small">Cancel</button></form></td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ------------------------------------------------------------------ *
 *  Statistics: anonymous records, patron-data purge, dashboard, CSV
 *
 *  Patron data (name, email, cancel token) lives only in the slots table,
 *  and only until a reservation ends or is cancelled. At that point the
 *  booking is "retired": an anonymous row (room, start, length, when it
 *  was booked, outcome) goes into the stats table and the slot rows are
 *  deleted. Requests turned away (slot taken, daily limit, email domain)
 *  are recorded the same way, so unmet demand shows up too.
 * ------------------------------------------------------------------ */

function rar_stats_table() {
	global $wpdb;
	return $wpdb->prefix . 'rar_stats';
}

function rar_room_name( $room_id ) {
	foreach ( rar_get()['rooms'] as $r ) {
		if ( $r['id'] === $room_id ) { return $r['name']; }
	}
	return 'Removed room';
}

/**
 * Delete a booking's slot rows and keep an anonymous record of it.
 * $kind: 'booked' (it ended) or 'cancelled'. Returns the number of slot rows deleted.
 * The record is only written when this call did the delete, so a booking is never counted twice.
 */
function rar_retire_booking( $booking_id, $kind, $reason = '' ) {
	global $wpdb;
	$table = rar_table();
	$b     = $wpdb->get_row( $wpdb->prepare(
		"SELECT room_id, MIN(slot_start) AS start_at, MIN(created_at) AS created_at FROM {$table} WHERE booking_id = %s GROUP BY room_id", // phpcs:ignore WordPress.DB.PreparedSQL
		$booking_id
	) );
	if ( ! $b ) { return 0; }
	$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE booking_id = %s", $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $n > 0 ) {
		$wpdb->insert( rar_stats_table(), array(
			'kind'         => $kind,
			'reason'       => $reason,
			'room_id'      => $b->room_id,
			'room_name'    => rar_room_name( $b->room_id ),
			'start_at'     => $b->start_at,
			'duration_min' => $n * 30,
			'created_at'   => $b->created_at,
			'cancelled_at' => 'cancelled' === $kind ? rar_now()->format( 'Y-m-d H:i:s' ) : null,
		) );
	}
	return $n;
}

/** Record a request that couldn't be booked. $reason: 'taken', 'daily' or 'domain'. */
function rar_log_denied( $reason, $room_id, $date, $start, $duration ) {
	global $wpdb;
	$valid = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && preg_match( '/^\d{2}:\d{2}$/', $start )
		&& false !== strtotime( $date . ' ' . $start );
	// Only keep a room id that exists, so junk requests can't add phantom rooms to the dashboard.
	if ( 'Removed room' === rar_room_name( (string) $room_id ) ) { $room_id = ''; }
	$wpdb->insert( rar_stats_table(), array(
		'kind'         => 'denied',
		'reason'       => $reason,
		'room_id'      => (string) $room_id,
		'room_name'    => '' !== (string) $room_id ? rar_room_name( $room_id ) : '',
		'start_at'     => $valid ? $date . ' ' . $start . ':00' : null,
		'duration_min' => max( 0, min( 480, (int) $duration ) ),
		'created_at'   => rar_now()->format( 'Y-m-d H:i:s' ),
	) );
}

/** Retire every booking that has ended, deleting its patron data. Runs hourly and on admin page loads. */
function rar_purge_past() {
	global $wpdb;
	$table = rar_table();
	$now   = rar_now()->format( 'Y-m-d H:i:s' );
	for ( $i = 0; $i < 20; $i++ ) { // bounded, in case a delete keeps failing
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT booking_id FROM {$table} GROUP BY booking_id HAVING DATE_ADD(MAX(slot_start), INTERVAL 30 MINUTE) <= %s LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL
			$now
		) );
		if ( ! $ids ) { break; }
		foreach ( $ids as $id ) { rar_retire_booking( $id, 'booked' ); }
		if ( count( $ids ) < 200 ) { break; }
	}
}
add_action( 'rar_purge', 'rar_purge_past' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'rar_purge' ) ) { wp_schedule_event( time() + 60, 'hourly', 'rar_purge' ); }
} );
register_deactivation_hook( __FILE__, function () { wp_clear_scheduled_hook( 'rar_purge' ); } );

/* --- Calculations --- */

/** Timestamps for naive local 'Y-m-d H:i:s' strings. Treated as UTC so wall-clock fields come back unchanged via gmdate(). */
function rar_ts( $local ) {
	return strtotime( $local . ' UTC' );
}

function rar_shift_date( $date, $days ) {
	return gmdate( 'Y-m-d', rar_ts( $date . ' 00:00:00' ) + $days * 86400 );
}

/** The dashboard's date range from ?from=&to=, defaulting to the last 30 days and capped at two years. */
function rar_stats_range() {
	$today = rar_now()->format( 'Y-m-d' );
	$get   = function ( $k ) {
		$v = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && false !== strtotime( $v ) ? $v : '';
	};
	$to   = $get( 'to' ) ? $get( 'to' ) : $today;
	$from = $get( 'from' ) ? $get( 'from' ) : rar_shift_date( $to, -29 );
	if ( $from > $to ) { list( $from, $to ) = array( $to, $from ); }
	$min = rar_shift_date( $to, -730 );
	if ( $from < $min ) { $from = $min; }
	return array( $from, $to );
}

function rar_pct( $num, $den ) {
	return $den > 0 ? (int) min( 100, round( 100 * $num / $den ) ) : null;
}

function rar_lead_buckets() {
	return array( 'Less than 1 hour' => 60, '1 to 24 hours' => 1440, '1 to 3 days' => 4320, '3 to 7 days' => 10080, 'More than 7 days' => PHP_INT_MAX );
}

function rar_reason_label( $reason ) {
	$labels = array(
		'taken'  => 'Time was already taken',
		'daily'  => 'Over the daily limit',
		'domain' => 'Email domain not allowed',
		'patron' => 'Patron (email link)',
		'staff'  => 'Staff',
	);
	return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
}

/**
 * Everything the dashboard and CSV show, for reservations starting between $from and $to (inclusive).
 * "In use" compares booked room-time with open time from Library Hours × the current number of rooms.
 */
function rar_stats( $from, $to ) {
	global $wpdb;
	$table = rar_stats_table();
	$next  = rar_shift_date( $to, 1 );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT kind, reason, room_id, room_name, start_at, duration_min, created_at, cancelled_at FROM {$table}
		 WHERE COALESCE(start_at, created_at) >= %s AND COALESCE(start_at, created_at) < %s ORDER BY id", // phpcs:ignore WordPress.DB.PreparedSQL
		$from . ' 00:00:00',
		$next . ' 00:00:00'
	), ARRAY_A );

	$cfg       = rar_get();
	$roomCount = count( $cfg['rooms'] );
	$s         = array(
		'from' => $from, 'to' => $to, 'roomCount' => $roomCount, 'hoursReady' => (bool) rar_hours_option(),
		'booked' => 0, 'minutes' => 0, 'cancelled' => 0, 'denied' => 0, 'under1h' => 0,
		'leads' => array(), 'openSlots' => 0, 'occSlots' => 0, 'fullHalfHours' => 0,
		'rooms' => array(), 'hours' => array(), 'days' => array(), 'heat' => array(),
		'lead' => array_fill_keys( array_keys( rar_lead_buckets() ), 0 ),
		'length' => array(), 'cancelBy' => array( 'patron' => 0, 'staff' => 0 ),
		'notice' => array( 'After it started' => 0, 'Less than 1 hour before' => 0, '1 to 24 hours before' => 0, 'More than a day before' => 0 ),
		'deniedBy' => array( 'taken' => 0, 'daily' => 0, 'domain' => 0 ), 'weeks' => array(),
	);
	$current = array();
	foreach ( $cfg['rooms'] as $r ) {
		$current[ $r['id'] ]    = true;
		$s['rooms'][ $r['id'] ] = array( 'name' => $r['name'], 'bookings' => 0, 'minutes' => 0, 'cancelled' => 0, 'denied' => 0, 'slots' => 0 );
	}
	for ( $d = 1; $d <= 7; $d++ ) {
		$s['days'][ $d ] = array( 'occ' => 0, 'avail' => 0, 'minutes' => 0 );
		$s['heat'][ $d ] = array();
	}
	$cell = function ( &$s, $dow, $h ) {
		if ( ! isset( $s['heat'][ $dow ][ $h ] ) ) { $s['heat'][ $dow ][ $h ] = array( 'occ' => 0, 'avail' => 0, 'full' => 0 ); }
		if ( ! isset( $s['hours'][ $h ] ) ) { $s['hours'][ $h ] = array( 'occ' => 0, 'avail' => 0 ); }
	};

	// Weeks (Monday start) across the range, so quiet weeks show as zero rather than disappearing.
	$w = rar_ts( $from . ' 00:00:00' );
	$w -= ( (int) gmdate( 'N', $w ) - 1 ) * 86400;
	for ( $end = rar_ts( $to . ' 00:00:00' ); $w <= $end; $w += 7 * 86400 ) {
		$s['weeks'][ gmdate( 'Y-m-d', $w ) ] = array( 'bookings' => 0, 'minutes' => 0 );
	}

	$occ = array(); // 'Y-m-d H:i' => rooms in use during that half-hour
	foreach ( $rows as $row ) {
		$rid = $row['room_id'];
		if ( '' !== $rid && ! isset( $s['rooms'][ $rid ] ) ) {
			$s['rooms'][ $rid ] = array( 'name' => $row['room_name'] ? $row['room_name'] : 'Removed room', 'bookings' => 0, 'minutes' => 0, 'cancelled' => 0, 'denied' => 0, 'slots' => 0 );
		}
		if ( 'denied' === $row['kind'] ) {
			$s['denied']++;
			if ( isset( $s['deniedBy'][ $row['reason'] ] ) ) { $s['deniedBy'][ $row['reason'] ]++; }
			if ( '' !== $rid ) { $s['rooms'][ $rid ]['denied']++; }
			continue;
		}
		$start = rar_ts( $row['start_at'] );
		$dur   = (int) $row['duration_min'];
		if ( 'cancelled' === $row['kind'] ) {
			$s['cancelled']++;
			$s['rooms'][ $rid ]['cancelled']++;
			if ( isset( $s['cancelBy'][ $row['reason'] ] ) ) { $s['cancelBy'][ $row['reason'] ]++; }
			$notice = $row['cancelled_at'] ? ( $start - rar_ts( $row['cancelled_at'] ) ) / 60 : 0;
			$key    = $notice < 0 ? 'After it started' : ( $notice < 60 ? 'Less than 1 hour before' : ( $notice < 1440 ? '1 to 24 hours before' : 'More than a day before' ) );
			$s['notice'][ $key ]++;
			continue;
		}
		// A reservation that was kept.
		$s['booked']++;
		$s['minutes'] += $dur;
		$s['rooms'][ $rid ]['bookings']++;
		$s['rooms'][ $rid ]['minutes'] += $dur;
		$s['length'][ $dur ] = isset( $s['length'][ $dur ] ) ? $s['length'][ $dur ] + 1 : 1;
		$lead          = max( 0, (int) round( ( $start - rar_ts( $row['created_at'] ) ) / 60 ) );
		$s['leads'][]  = $lead;
		if ( $lead < 60 ) { $s['under1h']++; }
		foreach ( rar_lead_buckets() as $label => $limit ) {
			if ( $lead < $limit ) { $s['lead'][ $label ]++; break; }
		}
		$dow = (int) gmdate( 'N', $start );
		$s['days'][ $dow ]['minutes'] += $dur;
		$wk = gmdate( 'Y-m-d', $start - ( $dow - 1 ) * 86400 );
		if ( isset( $s['weeks'][ $wk ] ) ) {
			$s['weeks'][ $wk ]['bookings']++;
			$s['weeks'][ $wk ]['minutes'] += $dur;
		}
		for ( $t = $start; $t < $start + $dur * 60; $t += 1800 ) {
			$d = (int) gmdate( 'N', $t );
			$h = (int) gmdate( 'G', $t );
			$cell( $s, $d, $h );
			$s['heat'][ $d ][ $h ]['occ']++;
			$s['hours'][ $h ]['occ']++;
			$s['days'][ $d ]['occ']++;
			$s['rooms'][ $rid ]['slots']++;
			$s['occSlots']++;
			if ( isset( $current[ $rid ] ) ) { // "every room booked" means every room that exists now
				$k         = gmdate( 'Y-m-d H:i', $t );
				$occ[ $k ] = isset( $occ[ $k ] ) ? $occ[ $k ] + 1 : 1;
			}
		}
	}

	// Open time, from Library Hours, for each day in the range up to today.
	$today = rar_now()->format( 'Y-m-d' );
	$last  = min( $to, $today );
	for ( $day = $from; $day <= $last; $day = rar_shift_date( $day, 1 ) ) {
		$dow = (int) gmdate( 'N', rar_ts( $day . ' 00:00:00' ) );
		foreach ( rar_candidate_slots( $day ) as $hhmm ) {
			$h = (int) substr( $hhmm, 0, 2 );
			$cell( $s, $dow, $h );
			$s['heat'][ $dow ][ $h ]['avail']++;
			$s['hours'][ $h ]['avail']++;
			$s['days'][ $dow ]['avail']++;
			$s['openSlots']++;
			// Every room in use for this half-hour.
			$k = $day . ' ' . $hhmm;
			if ( $roomCount > 0 && isset( $occ[ $k ] ) && $occ[ $k ] >= $roomCount ) {
				$s['heat'][ $dow ][ $h ]['full']++;
				$s['fullHalfHours']++;
			}
		}
	}
	ksort( $s['hours'] );
	ksort( $s['length'] );
	sort( $s['leads'] );
	$n                 = count( $s['leads'] );
	$s['leadAvg']      = $n ? (int) round( array_sum( $s['leads'] ) / $n ) : null;
	$s['leadMedian']   = $n ? ( $n % 2 ? $s['leads'][ intdiv( $n, 2 ) ] : (int) round( ( $s['leads'][ $n / 2 - 1 ] + $s['leads'][ $n / 2 ] ) / 2 ) ) : null;
	$s['avgLen']       = $s['booked'] ? (int) round( $s['minutes'] / $s['booked'] ) : null;
	$s['util']         = rar_pct( $s['occSlots'], $s['openSlots'] * $roomCount );
	$s['cancelRate']   = rar_pct( $s['cancelled'], $s['booked'] + $s['cancelled'] );
	$s['under1hPct']   = rar_pct( $s['under1h'], $s['booked'] );
	unset( $s['leads'] );
	uasort( $s['rooms'], function ( $a, $b ) { return $b['minutes'] - $a['minutes']; } );
	return $s;
}

/* --- Formatting --- */

function rar_hour_label( $h, $fmt12 ) {
	if ( ! $fmt12 ) { return sprintf( '%02d:00', $h ); }
	return ( 0 === $h % 12 ? 12 : $h % 12 ) . ( $h < 12 ? ' AM' : ' PM' );
}

function rar_day_names() {
	return array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );
}

/** Short human duration for lead times: "45 minutes", "5 hours", "2.5 days". */
function rar_fmt_lead( $min ) {
	if ( null === $min ) { return '—'; }
	if ( $min < 60 ) { return $min . ( 1 === $min ? ' minute' : ' minutes' ); }
	if ( $min < 2880 ) { $h = round( $min / 60, 1 ); return $h . ( 1.0 === (float) $h ? ' hour' : ' hours' ); }
	return round( $min / 1440, 1 ) . ' days';
}

function rar_fmt_hours( $min ) {
	return number_format_i18n( $min / 60, 0 === $min % 60 ? 0 : 1 );
}

/* --- Dashboard --- */

/**
 * A bar chart drawn as a table, so it reads correctly without the bars.
 * $rows: list of array( label, value, display text ). $max: bar scale (null = largest value).
 */
function rar_stats_bars( $caption, $item_head, $value_head, $rows, $max = null ) {
	if ( null === $max ) {
		$max = 0;
		foreach ( $rows as $r ) { $max = max( $max, $r[1] ); }
	}
	$o = '<table class="rar-bars"><caption>' . esc_html( $caption ) . '</caption><thead><tr><th scope="col">' . esc_html( $item_head )
		. '</th><th scope="col" class="num">' . esc_html( $value_head ) . '</th><td aria-hidden="true"></td></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$w  = $max > 0 ? round( 100 * $r[1] / $max, 1 ) : 0;
		$o .= '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td class="num">' . esc_html( $r[2] ) . '</td>'
			. '<td class="bar" aria-hidden="true"><span style="width:' . esc_attr( $w ) . '%"></span></td></tr>';
	}
	return $o . '</tbody></table>';
}

function rar_stats_page() {
	if ( ! current_user_can( 'edit_pages' ) ) { return; }
	rar_purge_past();
	list( $from, $to ) = rar_stats_range();
	$s     = rar_stats( $from, $to );
	$fmt12 = '12' === rar_get()['rules']['timeFormat'];
	$days  = rar_day_names();
	$today = rar_now()->format( 'Y-m-d' );
	$page  = admin_url( 'admin.php?page=reserve-a-room-stats' );
	$csv   = function ( $type ) use ( $from, $to ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=rar_stats_csv&type=' . $type . '&from=' . $from . '&to=' . $to ), 'rar_stats_csv' );
	};
	$hasUtil = $s['hoursReady'] && $s['openSlots'] > 0 && $s['roomCount'] > 0;

	echo rar_stats_css(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<div class="wrap rar-stats"><h1>Room statistics</h1>';
	echo '<p class="lede">How the study rooms are used, for planning space and staffing. Once a reservation ends, the patron’s name and email are deleted. Only the room, time, length and when it was booked are kept.</p>';

	// Filters: presets, custom range, downloads.
	echo '<form class="filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="reserve-a-room-stats">';
	echo '<div class="presets" role="group" aria-label="Quick ranges">';
	foreach ( array( 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months' ) as $n => $label ) {
		$pf  = rar_shift_date( $today, 1 - $n );
		$cur = ( $pf === $from && $today === $to ) ? ' aria-current="true"' : '';
		echo '<a href="' . esc_url( add_query_arg( array( 'from' => $pf, 'to' => $today ), $page ) ) . '"' . $cur . '>' . esc_html( $label ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
	echo '<label>From <input type="date" name="from" value="' . esc_attr( $from ) . '"></label>';
	echo '<label>To <input type="date" name="to" value="' . esc_attr( $to ) . '"></label>';
	echo '<button class="button">Show</button>';
	echo '<span class="downloads">Download CSV: <a class="button" href="' . esc_url( $csv( 'summary' ) ) . '">Summary</a> <a class="button" href="' . esc_url( $csv( 'records' ) ) . '">Records</a></span>';
	echo '</form>';

	if ( 0 === $s['booked'] + $s['cancelled'] + $s['denied'] ) {
		echo '<div class="empty"><p><strong>No reservations ended in this date range.</strong> A reservation is counted here once it ends or is cancelled. Pick a longer range, or check back after the rooms have been used.</p></div></div>';
		return;
	}

	// Plain-language summary: the answers staff look for first.
	$top_room = null;
	foreach ( $s['rooms'] as $r ) { if ( $r['minutes'] > 0 ) { $top_room = $r; break; } }
	$best_day = null; $best_day_v = -1; $best_h = null; $best_h_v = -1;
	foreach ( $s['days'] as $d => $v ) {
		$score = $hasUtil ? rar_pct( $v['occ'], $v['avail'] * $s['roomCount'] ) : $v['occ'];
		if ( null !== $score && $score > $best_day_v ) { $best_day_v = $score; $best_day = $d; }
	}
	foreach ( $s['hours'] as $h => $v ) {
		$score = $hasUtil ? rar_pct( $v['occ'], $v['avail'] * $s['roomCount'] ) : $v['occ'];
		if ( null !== $score && $score > $best_h_v ) { $best_h_v = $score; $best_h = $h; }
	}
	$lines = array();
	if ( $top_room ) {
		$lines[] = esc_html( $top_room['name'] ) . ' was the most used room, with ' . esc_html( rar_fmt_hours( $top_room['minutes'] ) ) . ' hours booked.';
	}
	if ( $s['booked'] > 0 && $best_day_v > 0 && null !== $best_h ) {
		$lines[] = 'Rooms were busiest on <strong>' . esc_html( $days[ $best_day ] ) . 's</strong>, and in the <strong>' . esc_html( rar_hour_label( $best_h, $fmt12 ) ) . '</strong> hour.';
	}
	if ( null !== $s['leadMedian'] ) {
		$lines[] = 'Half of all reservations were made within ' . esc_html( rar_fmt_lead( $s['leadMedian'] ) ) . ' of the start time.';
	}
	if ( $lines ) { echo '<p class="summary">' . implode( ' ', $lines ) . '</p>'; } // phpcs:ignore WordPress.Security.EscapeOutput

	// Key numbers.
	$kpis = array(
		array( 'Reservations', number_format_i18n( $s['booked'] ), rar_fmt_hours( $s['minutes'] ) . ' hours booked' ),
		array( 'Rooms in use', $hasUtil ? $s['util'] . '%' : '—', $hasUtil ? 'of the time the library was open' : 'needs Library Hours' ),
		array( 'Booked ahead', rar_fmt_lead( $s['leadMedian'] ), 'median; average ' . rar_fmt_lead( $s['leadAvg'] ) ),
		array( 'Last-minute', null === $s['under1hPct'] ? '—' : $s['under1hPct'] . '%', 'booked less than an hour ahead' ),
		array( 'Average length', null === $s['avgLen'] ? '—' : rar_fmt_minutes( $s['avgLen'] ), 'per reservation' ),
		array( 'Cancelled', null === $s['cancelRate'] ? '—' : $s['cancelRate'] . '%', number_format_i18n( $s['cancelled'] ) . ' reservations' ),
		array( 'Turned away', number_format_i18n( $s['denied'] ), 'requests that couldn’t be booked' ),
	);
	echo '<dl class="kpis">';
	foreach ( $kpis as $k ) {
		// "32.7 hours" -> big number, smaller unit, so values stay on one line.
		$v = explode( ' ', $k[1], 2 );
		echo '<div><dt>' . esc_html( $k[0] ) . '</dt><dd>' . esc_html( $v[0] ) . ( isset( $v[1] ) ? ' <span class="unit">' . esc_html( $v[1] ) . '</span>' : '' )
			. '</dd><dd class="sub">' . esc_html( $k[2] ) . '</dd></div>';
	}
	echo '</dl>';

	// Heatmap: share of open room-time in use, by weekday and hour.
	echo '<section class="panel wide"><h2>When rooms are in use</h2>';
	if ( $hasUtil ) {
		$hours = array();
		foreach ( $s['hours'] as $h => $v ) { if ( $v['avail'] > 0 ) { $hours[] = $h; } }
		echo '<p class="hint">Each square shows how much of the open room-time was booked, for that weekday and hour. A dark ring means every room was booked at least once in that hour.</p>';
		echo '<div class="scroll" tabindex="0" role="region" aria-label="When rooms are in use, by weekday and hour"><table class="rar-heat"><caption class="screen-reader-text">Percent of open room-time booked, by weekday (rows) and hour (columns)</caption><thead><tr><td></td>';
		foreach ( $hours as $h ) { echo '<th scope="col">' . esc_html( rar_hour_label( $h, $fmt12 ) ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $days as $d => $name ) {
			echo '<tr><th scope="row">' . esc_html( substr( $name, 0, 3 ) ) . '</th>';
			foreach ( $hours as $h ) {
				$c = isset( $s['heat'][ $d ][ $h ] ) ? $s['heat'][ $d ][ $h ] : array( 'occ' => 0, 'avail' => 0, 'full' => 0 );
				if ( 0 === $c['avail'] ) {
					echo '<td class="closed"><span class="screen-reader-text">Closed</span></td>';
					continue;
				}
				$p     = rar_pct( $c['occ'], $c['avail'] * $s['roomCount'] );
				$level = 0 === $p ? 0 : ( $p <= 10 ? 1 : ( $p <= 25 ? 2 : ( $p <= 50 ? 3 : ( $p <= 75 ? 4 : 5 ) ) ) );
				$tip   = $name . ', ' . rar_hour_label( $h, $fmt12 ) . ': ' . $p . '% in use'
					. ( $c['full'] ? '; every room booked for ' . ( $c['full'] * 30 ) . ' minutes in total' : '' );
				echo '<td class="l' . (int) $level . ( $c['full'] ? ' full' : '' ) . '" title="' . esc_attr( $tip ) . '">' . (int) $p . '%'
					. ( $c['full'] ? '<span class="screen-reader-text">, every room booked at times</span>' : '' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<div class="legend" aria-hidden="true"><span>Less used</span><i class="l0"></i><i class="l1"></i><i class="l2"></i><i class="l3"></i><i class="l4"></i><i class="l5"></i><span>More used</span><i class="closed"></i><span>Closed</span><i class="l3 full"></i><span>Every room booked</span></div>';
		echo '<p class="hint">Every room was booked at the same time for ' . esc_html( rar_fmt_hours( $s['fullHalfHours'] * 30 ) ) . ' hours in this range. Open time comes from Library Hours, counted for the ' . (int) $s['roomCount'] . ' rooms set up now.</p>';
	} else {
		echo '<p class="hint">This needs the Library Hours plugin and at least one room, to know when rooms could be booked.</p>';
	}
	echo '</section>';

	echo '<div class="panels">';

	// Rooms.
	echo '<section class="panel wide"><h2>Rooms</h2><div class="scroll" tabindex="0" role="region" aria-label="Rooms table"><table class="rar-bars rooms"><caption class="screen-reader-text">Use of each room</caption><thead><tr><th scope="col">Room</th><th scope="col" class="num">Reservations</th><th scope="col" class="num">Hours</th><th scope="col" class="num">In use</th><td aria-hidden="true"></td><th scope="col" class="num">Cancelled</th><th scope="col" class="num">Turned away</th></tr></thead><tbody>';
	foreach ( $s['rooms'] as $r ) {
		$p = $hasUtil ? rar_pct( $r['slots'], $s['openSlots'] ) : null;
		echo '<tr><th scope="row">' . esc_html( $r['name'] ) . '</th><td class="num">' . esc_html( number_format_i18n( $r['bookings'] ) ) . '</td><td class="num">'
			. esc_html( rar_fmt_hours( $r['minutes'] ) ) . '</td><td class="num">' . ( null === $p ? '—' : (int) $p . '%' ) . '</td><td class="bar" aria-hidden="true"><span style="width:' . (int) $p . '%"></span></td><td class="num">'
			. esc_html( number_format_i18n( $r['cancelled'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $r['denied'] ) ) . '</td></tr>';
	}
	echo '</tbody></table></div><p class="hint">“In use” is the share of open time each room was booked. “Turned away” counts requests for that room that failed, mostly because the time was already taken.</p></section>';

	// Time of day and day of week.
	$rows = array();
	foreach ( $s['hours'] as $h => $v ) {
		if ( 0 === $v['avail'] && 0 === $v['occ'] ) { continue; }
		$p      = $hasUtil ? rar_pct( $v['occ'], $v['avail'] * $s['roomCount'] ) : null;
		$rows[] = $hasUtil ? array( rar_hour_label( $h, $fmt12 ), (int) $p, $p . '%' ) : array( rar_hour_label( $h, $fmt12 ), $v['occ'], rar_fmt_hours( $v['occ'] * 30 ) . ' h' );
	}
	echo '<section class="panel"><h2>Busiest times of day</h2>' . rar_stats_bars( 'Share of open room-time booked, by hour', 'Hour', $hasUtil ? 'In use' : 'Booked', $rows, $hasUtil ? 100 : null ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput

	$rows = array();
	foreach ( $days as $d => $name ) {
		$v      = $s['days'][ $d ];
		$p      = $hasUtil ? rar_pct( $v['occ'], $v['avail'] * $s['roomCount'] ) : null;
		$label  = $name . ( $hasUtil && 0 === $v['avail'] ? ' (closed)' : '' );
		$rows[] = $hasUtil ? array( $label, (int) $p, null === $p ? '—' : $p . '%' ) : array( $name, $v['minutes'], rar_fmt_hours( $v['minutes'] ) . ' h' );
	}
	echo '<section class="panel"><h2>Busiest days of the week</h2>' . rar_stats_bars( 'Share of open room-time booked, by weekday', 'Day', $hasUtil ? 'In use' : 'Booked', $rows, $hasUtil ? 100 : null ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput

	// Lead time and length.
	$rows = array();
	foreach ( $s['lead'] as $label => $n ) { $rows[] = array( $label, $n, number_format_i18n( $n ) . ' (' . (int) rar_pct( $n, $s['booked'] ) . '%)' ); }
	echo '<section class="panel"><h2>How far ahead people book</h2>' . rar_stats_bars( 'Reservations by time between booking and start', 'Booked', 'Reservations', $rows ) // phpcs:ignore WordPress.Security.EscapeOutput
		. '<p class="hint">Median ' . esc_html( rar_fmt_lead( $s['leadMedian'] ) ) . ', average ' . esc_html( rar_fmt_lead( $s['leadAvg'] ) ) . '.</p></section>';

	$rows = array();
	foreach ( $s['length'] as $min => $n ) { $rows[] = array( rar_fmt_minutes( $min ), $n, number_format_i18n( $n ) . ' (' . (int) rar_pct( $n, $s['booked'] ) . '%)' ); }
	echo '<section class="panel"><h2>How long people book</h2>' . ( $rows ? rar_stats_bars( 'Reservations by length', 'Length', 'Reservations', $rows ) : '<p class="hint">No finished reservations yet.</p>' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput

	// Cancellations.
	$rows = array();
	foreach ( $s['notice'] as $label => $n ) { $rows[] = array( $label, $n, number_format_i18n( $n ) ); }
	$by = array();
	foreach ( $s['cancelBy'] as $k => $n ) { $by[] = array( rar_reason_label( $k ), $n, number_format_i18n( $n ) ); }
	echo '<section class="panel"><h2>Cancellations</h2>';
	if ( $s['cancelled'] > 0 ) {
		echo rar_stats_bars( 'Cancellations by notice given', 'Cancelled', 'Count', $rows ) . rar_stats_bars( 'Cancellations by who cancelled', 'Cancelled by', 'Count', $by ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p class="hint">No cancellations in this range.</p>';
	}
	echo '</section>';

	// Turned away.
	$rows = array();
	foreach ( $s['deniedBy'] as $k => $n ) { $rows[] = array( rar_reason_label( $k ), $n, number_format_i18n( $n ) ); }
	echo '<section class="panel"><h2>Requests turned away</h2>';
	if ( $s['denied'] > 0 ) {
		echo rar_stats_bars( 'Requests that couldn’t be booked, by reason', 'Reason', 'Requests', $rows ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="hint">Each failed attempt counts, so one person retrying counts more than once. Many “already taken” requests suggest more rooms are needed at those times.</p>';
	} else {
		echo '<p class="hint">No requests were turned away in this range.</p>';
	}
	echo '</section>';

	// Weekly trend.
	$rows = array();
	foreach ( $s['weeks'] as $wk => $v ) { $rows[] = array( 'Week of ' . wp_date( 'M j, Y', rar_ts( $wk . ' 12:00:00' ), new DateTimeZone( 'UTC' ) ), $v['minutes'], rar_fmt_hours( $v['minutes'] ) . ' h, ' . number_format_i18n( $v['bookings'] ) ); }
	echo '<section class="panel wide"><h2>Week by week</h2>' . rar_stats_bars( 'Hours booked and reservations per week', 'Week', 'Hours, reservations', $rows ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput

	echo '</div></div>';
}

function rar_stats_css() {
	return <<<'HTML'
<style>
  .rar-stats{--ink:#1d2327;--muted:#50575e;--line:#dcdcde;--accent:#00539b;--track:#e7edf4;max-width:1180px;color:var(--ink)}
  .rar-stats .lede{max-width:72ch;color:var(--muted);font-size:14px}
  .rar-stats .filters{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:8px;padding:12px 14px;margin:16px 0 8px}
  .rar-stats .filters label{font-weight:600}
  .rar-stats .filters input[type=date]{margin-left:4px}
  .rar-stats .presets{display:inline-flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:6px;overflow:hidden}
  .rar-stats .presets a{padding:5px 10px;text-decoration:none;color:var(--ink);background:#fff}
  .rar-stats .presets a + a{border-left:1px solid var(--line)}
  .rar-stats .presets a[aria-current]{background:var(--accent);color:#fff}
  .rar-stats .presets a:focus{box-shadow:inset 0 0 0 2px #003b71;outline:0}
  .rar-stats .downloads{margin-left:auto;color:var(--muted)}
  .rar-stats .summary{font-size:1.3rem;line-height:1.55;max-width:62ch;margin:22px 0 6px}
  .rar-stats .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin:18px 0 26px;border-top:3px solid var(--accent)}
  .rar-stats .kpis > div{padding:12px 14px 4px 0;margin-right:14px;border-bottom:1px solid var(--line)}
  .rar-stats .kpis dt{font-size:13px;color:var(--muted)}
  .rar-stats .kpis dd{margin:0;font-size:1.9rem;font-weight:600;line-height:1.25;font-variant-numeric:tabular-nums}
  .rar-stats .kpis dd.sub{font-size:12px;font-weight:400;color:var(--muted);line-height:1.4}
  .rar-stats .panels{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,440px),1fr));gap:8px 36px}
  .rar-stats .panel{padding:4px 0 18px}
  .rar-stats .panel.wide{grid-column:1/-1}
  .rar-stats .panel h2{font-size:1.1rem;margin:14px 0 10px;padding-top:12px;border-top:1px solid var(--line)}
  .rar-stats .hint{color:var(--muted);font-size:13px;max-width:80ch}
  .rar-stats .empty{background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 16px;max-width:72ch}
  .rar-stats .scroll{overflow-x:auto}
  .rar-stats .scroll:focus{outline:2px solid #003b71;outline-offset:2px}
  .rar-stats .rar-heat{border-collapse:separate;border-spacing:3px;font-size:12px;font-variant-numeric:tabular-nums}
  .rar-stats .rar-heat th{font-weight:600;color:var(--muted);white-space:nowrap;padding:2px 4px}
  .rar-stats .rar-heat tbody th{text-align:left;color:var(--ink);padding-right:8px}
  .rar-stats .rar-heat td{min-width:44px;height:34px;text-align:center;border-radius:4px}
  .rar-stats .l0{background:#f3f6fa;color:#646970}
  .rar-stats .l1{background:#dbe8f5}
  .rar-stats .l2{background:#b4cfea}
  .rar-stats .l3{background:#7fabd6}
  .rar-stats .l4{background:#2f6fae;color:#fff}
  .rar-stats .l5{background:#00396b;color:#fff}
  .rar-stats .closed{background:repeating-linear-gradient(45deg,#fff,#fff 3px,#eceef0 3px,#eceef0 6px)}
  .rar-stats .full{box-shadow:inset 0 0 0 3px #1d2327}
  .rar-stats .l4.full,.rar-stats .l5.full{box-shadow:inset 0 0 0 3px #1d2327,inset 0 0 0 5px #fff}
  .rar-stats .legend{display:flex;flex-wrap:wrap;align-items:center;gap:4px 6px;font-size:12px;color:var(--muted);margin:8px 0}
  .rar-stats .legend i{display:inline-block;width:22px;height:16px;border-radius:3px}
  .rar-stats .legend i.closed{margin-left:14px;border:1px solid var(--line)}
  .rar-stats .legend i.full{margin-left:14px}
  .rar-stats .rar-bars{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums;margin-bottom:8px}
  .rar-stats .rar-bars caption{text-align:left;color:var(--muted);font-size:13px;padding-bottom:6px}
  .rar-stats .rar-bars th,.rar-stats .rar-bars td{padding:5px 10px 5px 0;text-align:left;font-weight:400;border-bottom:1px solid #f0f0f1}
  .rar-stats .rar-bars thead th{font-weight:600;color:var(--muted);font-size:12px}
  .rar-stats .rar-bars tbody th{white-space:nowrap}
  .rar-stats .rar-bars .num{text-align:right;white-space:nowrap}
  .rar-stats .rar-bars td.bar{width:45%;padding-left:6px}
  .rar-stats .rar-bars td.bar span{display:block;height:12px;min-width:2px;background:var(--accent);border-radius:0 4px 4px 0}
  .rar-stats .rar-bars td.bar{background:linear-gradient(var(--track),var(--track)) no-repeat left center/100% 12px;background-origin:content-box;background-clip:content-box}
  .rar-stats .rar-bars.rooms{min-width:640px}
  .rar-stats .rar-bars.rooms td.bar{width:24%}
  .rar-stats .kpis .unit{font-size:1rem;font-weight:400}
  @media (max-width:782px){.rar-stats .downloads{margin-left:0}.rar-stats .summary{font-size:1.1rem}}
</style>
HTML;
}

/* --- CSV export --- */

/** Stop spreadsheet apps treating a text cell as a formula. */
function rar_csv_cell( $v ) {
	if ( is_string( $v ) && '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) { return "'" . $v; }
	return $v;
}

add_action( 'admin_post_rar_stats_csv', function () {
	if ( ! current_user_can( 'edit_pages' ) ) { wp_die( 'You are not allowed to download room statistics.', 403 ); }
	check_admin_referer( 'rar_stats_csv' );
	rar_purge_past();
	list( $from, $to ) = rar_stats_range();
	$type  = ( isset( $_GET['type'] ) && 'records' === $_GET['type'] ) ? 'records' : 'summary';
	$fmt12 = '12' === rar_get()['rules']['timeFormat'];
	$days  = rar_day_names();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="room-' . $type . '-' . $from . '-to-' . $to . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8
	$put = function ( $row ) use ( $out ) { fputcsv( $out, array_map( 'rar_csv_cell', $row ), ',', '"', '\\' ); };

	if ( 'records' === $type ) {
		global $wpdb;
		$table = rar_stats_table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT kind, reason, room_name, start_at, duration_min, created_at, cancelled_at FROM {$table}
			 WHERE COALESCE(start_at, created_at) >= %s AND COALESCE(start_at, created_at) < %s ORDER BY COALESCE(start_at, created_at)", // phpcs:ignore WordPress.DB.PreparedSQL
			$from . ' 00:00:00',
			rar_shift_date( $to, 1 ) . ' 00:00:00'
		), ARRAY_A );
		$put( array( 'outcome', 'reason', 'room', 'date', 'weekday', 'start', 'length_minutes', 'booked_or_requested_at', 'lead_time_minutes', 'cancelled_at' ) );
		$outcome = array( 'booked' => 'reserved', 'cancelled' => 'cancelled', 'denied' => 'turned away' );
		foreach ( $rows as $r ) {
			$st = $r['start_at'] ? rar_ts( $r['start_at'] ) : null;
			$put( array(
				$outcome[ $r['kind'] ],
				$r['reason'] ? rar_reason_label( $r['reason'] ) : '',
				$r['room_name'],
				$st ? gmdate( 'Y-m-d', $st ) : '',
				$st ? $days[ (int) gmdate( 'N', $st ) ] : '',
				$st ? gmdate( 'H:i', $st ) : '',
				(int) $r['duration_min'],
				$r['created_at'],
				$st && 'denied' !== $r['kind'] ? max( 0, (int) round( ( $st - rar_ts( $r['created_at'] ) ) / 60 ) ) : '',
				(string) $r['cancelled_at'],
			) );
		}
		fclose( $out );
		exit;
	}

	$s       = rar_stats( $from, $to );
	$hasUtil = $s['hoursReady'] && $s['openSlots'] > 0 && $s['roomCount'] > 0;
	$util    = function ( $occ, $avail ) use ( $s, $hasUtil ) { return $hasUtil ? rar_pct( $occ, $avail * $s['roomCount'] ) : ''; };
	$put( array( 'section', 'item', 'measure', 'value' ) );
	$put( array( 'Range', 'From', 'date', $from ) );
	$put( array( 'Range', 'To', 'date', $to ) );
	$put( array( 'Overview', 'Reservations', 'count', $s['booked'] ) );
	$put( array( 'Overview', 'Hours booked', 'hours', round( $s['minutes'] / 60, 1 ) ) );
	$put( array( 'Overview', 'Rooms in use', 'percent of open time', $hasUtil ? $s['util'] : '' ) );
	$put( array( 'Overview', 'Average length', 'minutes', $s['avgLen'] ) );
	$put( array( 'Overview', 'Median lead time', 'minutes', $s['leadMedian'] ) );
	$put( array( 'Overview', 'Average lead time', 'minutes', $s['leadAvg'] ) );
	$put( array( 'Overview', 'Booked less than 1 hour ahead', 'percent', $s['under1hPct'] ) );
	$put( array( 'Overview', 'Cancelled', 'count', $s['cancelled'] ) );
	$put( array( 'Overview', 'Cancellation rate', 'percent', $s['cancelRate'] ) );
	$put( array( 'Overview', 'Turned away', 'count', $s['denied'] ) );
	$put( array( 'Overview', 'Every room booked at once', 'hours', round( $s['fullHalfHours'] / 2, 1 ) ) );
	foreach ( $s['rooms'] as $r ) {
		$put( array( 'Room', $r['name'], 'reservations', $r['bookings'] ) );
		$put( array( 'Room', $r['name'], 'hours booked', round( $r['minutes'] / 60, 1 ) ) );
		$put( array( 'Room', $r['name'], 'percent in use', $hasUtil ? rar_pct( $r['slots'], $s['openSlots'] ) : '' ) );
		$put( array( 'Room', $r['name'], 'cancelled', $r['cancelled'] ) );
		$put( array( 'Room', $r['name'], 'turned away', $r['denied'] ) );
	}
	foreach ( $s['hours'] as $h => $v ) {
		$put( array( 'Time of day', rar_hour_label( $h, $fmt12 ), 'hours booked', $v['occ'] / 2 ) );
		$put( array( 'Time of day', rar_hour_label( $h, $fmt12 ), 'percent in use', $util( $v['occ'], $v['avail'] ) ) );
	}
	foreach ( $days as $d => $name ) {
		$put( array( 'Day of week', $name, 'hours booked', $s['days'][ $d ]['occ'] / 2 ) );
		$put( array( 'Day of week', $name, 'percent in use', $util( $s['days'][ $d ]['occ'], $s['days'][ $d ]['avail'] ) ) );
	}
	foreach ( $days as $d => $name ) {
		foreach ( $s['heat'][ $d ] as $h => $c ) {
			if ( 0 === $c['avail'] && 0 === $c['occ'] ) { continue; }
			$item = $name . ' ' . rar_hour_label( $h, $fmt12 );
			$put( array( 'Day and hour', $item, 'percent in use', $util( $c['occ'], $c['avail'] ) ) );
			$put( array( 'Day and hour', $item, 'minutes with every room booked', $c['full'] * 30 ) );
		}
	}
	foreach ( $s['lead'] as $label => $n ) { $put( array( 'Booked ahead', $label, 'reservations', $n ) ); }
	foreach ( $s['length'] as $min => $n ) { $put( array( 'Length', $min . ' minutes', 'reservations', $n ) ); }
	foreach ( $s['cancelBy'] as $k => $n ) { $put( array( 'Cancelled by', rar_reason_label( $k ), 'count', $n ) ); }
	foreach ( $s['notice'] as $label => $n ) { $put( array( 'Cancellation notice', $label, 'count', $n ) ); }
	foreach ( $s['deniedBy'] as $k => $n ) { $put( array( 'Turned away', rar_reason_label( $k ), 'count', $n ) ); }
	foreach ( $s['weeks'] as $wk => $v ) {
		$put( array( 'Week starting', $wk, 'reservations', $v['bookings'] ) );
		$put( array( 'Week starting', $wk, 'hours booked', round( $v['minutes'] / 60, 1 ) ) );
	}
	fclose( $out );
	exit;
} );

/* ------------------------------------------------------------------ *
 *  Front-end  [reserve_a_room]
 * ------------------------------------------------------------------ */

add_shortcode( 'reserve_a_room', 'rar_shortcode' );

function rar_shortcode( $atts ) {
	$cfg = array(
		'availUrl'   => esc_url_raw( rest_url( 'reserve-a-room/v1/availability' ) ),
		'configUrl'  => esc_url_raw( rest_url( 'reserve-a-room/v1/config' ) ),
		'reserveUrl' => esc_url_raw( rest_url( 'reserve-a-room/v1/reserve' ) ),
		'nonce'      => wp_create_nonce( 'wp_rest' ),
	);
	$rules          = rar_get()['rules'];
	$cfg['fmt12']   = ( '12' === $rules['timeFormat'] );
	$cfg['domains'] = array_values( $rules['emailDomains'] );
	$config_js = '<script>window.RAR_CFG=' . wp_json_encode( $cfg ) . ';</script>';
	return rar_widget_markup() . $config_js . rar_widget_script();
}

function rar_widget_markup() {
	return <<<'HTML'
<div id="rar-rooms" class="rar-rooms" aria-live="polite">
  <div class="rar-rooms__bar">
    <label>Date <input type="date" id="rar-rooms-date"></label>
    <span class="rar-rooms__fmt" role="group" aria-label="Time format">
      <button type="button" id="rar-rooms-fmt24" class="fmtbtn on" aria-pressed="true">24h</button>
      <button type="button" id="rar-rooms-fmt12" class="fmtbtn" aria-pressed="false">12h</button>
    </span>
  </div>
  <div id="rar-rooms-grid"><p class="rar-rooms__note">Loading…</p></div>
  <div id="rar-rooms-form"></div>
</div>
<style>
  #rar-rooms{--accent:#00539b;--line:#dcdcdc;--grid:#8b9198;--muted:#666;--free:#bfe6cb;--freeb:#1a7f37;--taken:#c9cccf;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;max-width:100%}
  #rar-rooms .rar-rooms__bar{margin:0 0 12px;font-weight:600;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  #rar-rooms input[type=date]{font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px}
  #rar-rooms .rar-rooms__fmt{display:inline-flex;border:1px solid var(--line);border-radius:6px;overflow:hidden}
  #rar-rooms .fmtbtn{font:inherit;font-weight:600;background:#fff;color:#1a1a1a;border:0;padding:6px 10px;cursor:pointer}
  #rar-rooms .fmtbtn + .fmtbtn{border-left:1px solid var(--line)}
  #rar-rooms .fmtbtn.on{background:var(--accent);color:#fff}
  #rar-rooms .rar-rooms__scroll{overflow-x:auto}
  #rar-rooms table{border-collapse:collapse;font-size:.8rem}
  #rar-rooms th,#rar-rooms td{border:1px solid var(--grid);padding:0}
  #rar-rooms table{border:1px solid var(--grid)}
  #rar-rooms th{background:#f6f8fa;padding:4px 6px;white-space:nowrap;font-weight:600;min-width:52px}
  #rar-rooms th.rar-rooms__room{text-align:left;position:sticky;left:0;z-index:2;background:#f6f8fa;min-width:150px}
  #rar-rooms th.rar-rooms__rowhead{text-align:left;position:sticky;left:0;z-index:1;background:#fff;font-weight:600;padding:4px 6px;white-space:nowrap;min-width:150px}
  #rar-rooms .rar-rooms__rhwrap{display:flex;align-items:center;gap:8px}
  #rar-rooms .rar-rooms__info{margin-left:auto;flex:0 0 auto;width:16px;height:16px;line-height:16px;padding:0;border:0;text-align:center;border-radius:50%;background:var(--accent);color:#fff;font-family:inherit;font-size:11px;font-weight:700;font-style:normal;cursor:pointer}
  #rar-rooms .rar-rooms__info:focus-visible{outline:2px solid #003b71;outline-offset:2px}
  #rar-rooms .rar-rooms__notetext{display:block;max-width:220px;margin-top:4px;white-space:normal;font-weight:400;color:#1a1a1a}
  #rar-rooms .rar-rooms__notetext[hidden]{display:none}
  #rar-rooms .rar-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
  #rar-rooms .slot{width:100%;min-width:52px;height:34px;display:block}
  #rar-rooms .slot.free{background:var(--free);cursor:pointer}
  #rar-rooms .slot.free:hover{background:var(--freeb)}
  #rar-rooms .slot.free:focus{outline:3px solid #003b71;outline-offset:-3px;background:var(--freeb)}
  #rar-rooms .slot.taken{background:var(--taken);background-image:repeating-linear-gradient(45deg,transparent,transparent 4px,rgba(0,0,0,.06) 4px,rgba(0,0,0,.06) 8px)}
  #rar-rooms .rar-rooms__note{color:var(--muted);padding:8px 0}
  #rar-rooms .rar-rooms__legend{font-size:.8rem;color:var(--muted);margin-top:8px}
  #rar-rooms .rar-rooms__kbd{margin:0 0 10px}
  #rar-rooms .rar-rooms__legend b{display:inline-block;width:12px;height:12px;vertical-align:-2px;border:1px solid var(--line)}
  #rar-rooms .rar-rooms__legend b.is-free{background:var(--free)}
  #rar-rooms .rar-rooms__legend b.is-taken{background:var(--taken)}
  #rar-rooms-form{max-width:420px;margin-top:16px}
  #rar-rooms-form .fld{margin:10px 0}
  #rar-rooms-form label{display:block;font-weight:600;margin-bottom:3px}
  #rar-rooms-form input,#rar-rooms-form select{width:100%;font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:6px;box-sizing:border-box}
  #rar-rooms-form .err{color:#b42318;font-size:.85rem;margin-top:3px}
  #rar-rooms-form .hint{color:var(--muted);font-size:.85rem;margin-top:3px}
  #rar-rooms-form .go{background:var(--accent);color:#fff;border:0;padding:10px 18px;border-radius:7px;font-weight:600;cursor:pointer}
  #rar-rooms-form .cancel{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;margin-left:10px}
  #rar-rooms-form .note{background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;padding:12px 14px;margin-bottom:14px}
</style>
HTML;
}

function rar_widget_script() {
	return <<<'HTML'
<script>
(function(){
"use strict";
var CFG=window.RAR_CFG, root=document.getElementById("rar-rooms");
if(!root||!CFG)return;
var gridEl=document.getElementById("rar-rooms-grid"), formEl=document.getElementById("rar-rooms-form");
var dateEl=document.getElementById("rar-rooms-date");
var fmt24Btn=document.getElementById("rar-rooms-fmt24"), fmt12Btn=document.getElementById("rar-rooms-fmt12");
var AVAIL=null, fmt12=false; // display-only; all data/requests/emails stay 24h "HH:MM"
var notesShown={}; // room id -> true while its note is open, so a redraw keeps it open
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function pad(n){return n<10?"0"+n:""+n;}
function todayStr(){var d=new Date();return d.getFullYear()+"-"+pad(d.getMonth()+1)+"-"+pad(d.getDate());}
function m2hhmm(m){return pad(Math.floor(m/60))+":"+pad(m%60);}
function hhmm2m(s){var a=s.split(":");return (+a[0])*60+(+a[1]);}
function fmtMins(m){m=+m;if(m>0&&m%60===0){var h=m/60;return h+(h===1?" hour":" hours");}return m+" minutes";}
function dispTime(s){ // display-only "HH:MM" -> "h:mm AM/PM" when fmt12 is on; raw hhmm is never sent anywhere
  if(!fmt12)return s;
  var a=s.split(":"),h=+a[0],mm=a[1],ap=h<12?"AM":"PM",h12=h%12;if(h12===0)h12=12;
  return h12+":"+mm+" "+ap;
}
function setFmt(is12){
  fmt12=is12;
  fmt12Btn.classList.toggle("on",is12);fmt12Btn.setAttribute("aria-pressed",is12?"true":"false");
  fmt24Btn.classList.toggle("on",!is12);fmt24Btn.setAttribute("aria-pressed",is12?"false":"true");
  if(AVAIL)renderGrid();
  if(curOpen)openForm(curOpen.roomId,curOpen.start);
}
if(CFG.fmt12)setFmt(true); // admin-chosen default; patrons can still toggle
var DOMAINS=CFG.domains||[]; // allowed email domains (empty = anyone); the server enforces this too
function domainOk(email){
  if(!DOMAINS.length)return true;
  var host=email.slice(email.lastIndexOf("@")+1).toLowerCase();
  return DOMAINS.some(function(d){return host===d||host.slice(-(d.length+1))==="."+d;});
}
function domainsPhrase(){
  var d=DOMAINS.map(function(x){return "@"+x;});
  if(d.length<3)return d.join(" or ");
  return d.slice(0,-1).join(", ")+", or "+d[d.length-1];
}
fmt24Btn.addEventListener("click",function(){setFmt(false);});
fmt12Btn.addEventListener("click",function(){setFmt(true);});
fetch(CFG.configUrl).then(function(r){return r.json();}).then(function(c){
  var adv=(c.rules&&c.rules.advanceDays)||14;
  var t=todayStr();dateEl.min=t;dateEl.value=t;
  var max=new Date();max.setDate(max.getDate()+adv);dateEl.max=max.getFullYear()+"-"+pad(max.getMonth()+1)+"-"+pad(max.getDate());
  load(t);
}).catch(function(){gridEl.innerHTML='<p class="rar-rooms__note">Room booking is temporarily unavailable.</p>';});
dateEl.addEventListener("change",function(){formEl.innerHTML="";load(dateEl.value);});
function load(date){
  formEl.innerHTML="";gridEl.innerHTML='<p class="rar-rooms__note">Loading…</p>';
  var url=CFG.availUrl+(CFG.availUrl.indexOf("?")>-1?"&":"?")+"date="+encodeURIComponent(date)+"&v="+Date.now();
  fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)return r.json().then(function(j){throw new Error(j.message||"Unavailable");});return r.json();})
    .then(function(a){AVAIL=a;renderGrid();}).catch(function(e){gridEl.innerHTML='<p class="rar-rooms__note">'+esc(e.message)+'</p>';});
}
function renderGrid(){
  var a=AVAIL;
  if(!a.slots||a.slots.length===0){gridEl.innerHTML='<p class="rar-rooms__note">The library is closed on this date — no rooms are bookable.</p>';return;}
  if(!a.rooms||a.rooms.length===0){gridEl.innerHTML='<p class="rar-rooms__note">No rooms are set up yet.</p>';return;}
  var cols=a.slots, COLS=cols.length, ROWS=a.rooms.length;
  var html='<p class="rar-rooms__legend rar-rooms__kbd">Keyboard: press Tab to reach the grid, use the arrow keys to move between available times, then press Enter to book.</p>'
    +'<div class="rar-rooms__scroll"><table>'
    +'<caption class="rar-sr">Room availability for '+esc(a.date)+'. Use the arrow keys to move between available times and press Enter to book.</caption>'
    +'<thead><tr><th scope="col" class="rar-rooms__room">Room</th>';
  cols.forEach(function(s){html+='<th scope="col">'+esc(dispTime(s))+'</th>';});
  html+='</tr></thead><tbody>';
  a.rooms.forEach(function(room,ri){
    var taken=(a.taken&&a.taken[room.id])||[];
    // The "i" is a disclosure button: click, tap, Enter or Space shows the
    // note under the room name; Esc hides it again.
    var noteId="rar-rooms-note-"+ri, shown=!!notesShown[room.id];
    var info=room.notes?'<button type="button" class="rar-rooms__info" data-room="'+esc(room.id)+'" aria-expanded="'+shown+'" aria-controls="'+noteId+'" aria-label="Details for '+esc(room.name)+'">i</button>':'';
    var note=room.notes?'<span class="rar-rooms__notetext" id="'+noteId+'"'+(shown?'':' hidden')+'>'+esc(room.notes)+'</span>':'';
    html+='<tr><th scope="row" class="rar-rooms__rowhead"><span class="rar-rooms__rhwrap"><span>'+esc(room.name)+'</span>'+info+'</span>'+note+'</th>';
    cols.forEach(function(s,ci){
      var isTaken=taken.indexOf(s)>-1;
      var past=(a.nowMin!=null)&&(hhmm2m(s)<a.nowMin);
      if(isTaken||past){html+='<td><span class="slot taken" title="'+(isTaken?"Booked":"Past")+'"></span></td>';}
      else{html+='<td><span class="slot free" role="button" tabindex="-1" data-ri="'+ri+'" data-ci="'+ci+'" data-room="'+esc(room.id)+'" data-start="'+esc(s)+'" aria-label="Book '+esc(room.name)+' at '+esc(dispTime(s))+', '+esc(a.date)+'"></span></td>';}
    });
    html+='</tr>';
  });
  html+='</tbody></table></div>'
    +'<p class="rar-rooms__legend"><b class="is-free" aria-hidden="true"></b> <strong>Green = available</strong> — click or press Enter on a green square to book &nbsp;&nbsp; <b class="is-taken" aria-hidden="true"></b> grey = unavailable</p>'
    +(a.dailyMaxMin>0?'<p class="rar-rooms__legend">Each person may book up to '+esc(fmtMins(a.dailyMaxMin))+' per day across all rooms.</p>':'');
  gridEl.innerHTML=html;
  [].forEach.call(gridEl.querySelectorAll(".rar-rooms__info"),function(btn){
    var note=document.getElementById(btn.getAttribute("aria-controls"));
    function show(on){
      btn.setAttribute("aria-expanded",on?"true":"false");note.hidden=!on;
      if(on)notesShown[btn.getAttribute("data-room")]=true;else delete notesShown[btn.getAttribute("data-room")];
    }
    btn.addEventListener("click",function(){show(note.hidden);});
    btn.addEventListener("keydown",function(e){if(e.key==="Escape"&&!note.hidden){e.preventDefault();show(false);}});
  });
  // Roving tabindex + 2-D arrow-key navigation over the available (green) cells.
  var frees=[].slice.call(gridEl.querySelectorAll(".slot.free"));
  var map={};
  frees.forEach(function(el){map[el.getAttribute("data-ri")+"_"+el.getAttribute("data-ci")]=el;});
  if(frees.length){frees[0].tabIndex=0;}
  function focusCell(el){if(!el)return;frees.forEach(function(f){f.tabIndex=-1;});el.tabIndex=0;el.focus();}
  frees.forEach(function(el){
    var open=function(){openForm(el.getAttribute("data-room"),el.getAttribute("data-start"));};
    el.addEventListener("click",open);
    el.addEventListener("keydown",function(e){
      var ri=+el.getAttribute("data-ri"), ci=+el.getAttribute("data-ci"), r, c, t;
      if(e.key==="Enter"||e.key===" "){e.preventDefault();open();return;}
      if(e.key==="ArrowRight"){for(c=ci+1;c<COLS;c++){t=map[ri+"_"+c];if(t){e.preventDefault();focusCell(t);return;}}}
      else if(e.key==="ArrowLeft"){for(c=ci-1;c>=0;c--){t=map[ri+"_"+c];if(t){e.preventDefault();focusCell(t);return;}}}
      else if(e.key==="ArrowDown"){for(r=ri+1;r<ROWS;r++){t=map[r+"_"+ci];if(t){e.preventDefault();focusCell(t);return;}}}
      else if(e.key==="ArrowUp"){for(r=ri-1;r>=0;r--){t=map[r+"_"+ci];if(t){e.preventDefault();focusCell(t);return;}}}
      else if(e.key==="Home"){for(c=0;c<COLS;c++){t=map[ri+"_"+c];if(t){e.preventDefault();focusCell(t);return;}}}
      else if(e.key==="End"){for(c=COLS-1;c>=0;c--){t=map[ri+"_"+c];if(t){e.preventDefault();focusCell(t);return;}}}
    });
  });
}
function freeRun(roomId,start){
  var a=AVAIL, taken=(a.taken&&a.taken[roomId])||[], set={};a.slots.forEach(function(s){set[s]=1;});
  var maxSlots=Math.floor(a.maxMin/30), n=0, m=hhmm2m(start);
  for(var k=0;k<maxSlots;k++){var s=m2hhmm(m+k*30);
    if(!set[s])break; if(taken.indexOf(s)>-1)break;
    if(a.nowMin!=null&&(m+k*30)<a.nowMin)break;
    n++;}
  return n;
}
var curOpen=null; // {roomId,start} of the open reservation form, so a format toggle can re-render it
function openForm(roomId,start){
  var a=AVAIL, room=null;a.rooms.forEach(function(r){if(r.id===roomId)room=r;});
  var runs=freeRun(roomId,start); if(runs<1)return;
  curOpen={roomId:roomId,start:start};
  var opts="";for(var k=1;k<=runs;k++){var mins=k*30;opts+='<option value="'+mins+'">'+mins+' min ('+dispTime(start)+' – '+dispTime(m2hhmm(hhmm2m(start)+mins))+')</option>';}
  formEl.innerHTML='<div class="note" role="status" aria-live="polite">Reserve <strong>'+esc(room.name)+'</strong> on '+esc(a.date)+' starting <strong>'+esc(dispTime(start))+'</strong>.</div>'
    +'<form id="rar-rf" novalidate aria-label="Reserve '+esc(room.name)+' at '+esc(dispTime(start))+'">'
    +'<div class="fld"><label for="rar-rf-dur">Length</label><select id="rar-rf-dur">'+opts+'</select></div>'
    +'<div class="fld"><label for="rar-rf-first">First name</label><input type="text" id="rar-rf-first" autocomplete="given-name" aria-describedby="rar-rf-first-e"><div class="err" id="rar-rf-first-e" data-e="first" role="alert"></div></div>'
    +'<div class="fld"><label for="rar-rf-last">Last name</label><input type="text" id="rar-rf-last" autocomplete="family-name" aria-describedby="rar-rf-last-e"><div class="err" id="rar-rf-last-e" data-e="last" role="alert"></div></div>'
    +'<div class="fld"><label for="rar-rf-email">Email</label><input type="email" id="rar-rf-email" autocomplete="email" aria-describedby="'+(DOMAINS.length?'rar-rf-email-h ':'')+'rar-rf-email-e">'+(DOMAINS.length?'<div class="hint" id="rar-rf-email-h">Use your '+esc(domainsPhrase())+' address.</div>':'')+'<div class="err" id="rar-rf-email-e" data-e="email" role="alert"></div></div>'
    +'<input type="text" name="rar_hp" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true">'
    +'<button type="submit" class="go">Reserve</button><button type="button" class="cancel" id="rar-rf-cancel">Cancel</button></form>';
  formEl.scrollIntoView({behavior:"smooth",block:"start"});
  document.getElementById("rar-rf-cancel").addEventListener("click",function(){formEl.innerHTML="";curOpen=null;var f=gridEl.querySelector('.slot.free[tabindex="0"]');if(f)f.focus();});
  document.getElementById("rar-rf").addEventListener("submit",function(e){e.preventDefault();submit(roomId,start);});
  document.getElementById("rar-rf-dur").focus(); // move keyboard focus into the form
}
function err(k,m){var e=formEl.querySelector('[data-e="'+k+'"]');if(e)e.textContent=m||"";}
function submit(roomId,start){
  var first=document.getElementById("rar-rf-first").value.trim();
  var last=document.getElementById("rar-rf-last").value.trim();
  var email=document.getElementById("rar-rf-email").value.trim();
  var dur=document.getElementById("rar-rf-dur").value;
  var ok=true, firstBad=null;err("first","");err("last","");err("email","");
  if(!first){err("first","Required.");ok=false;firstBad=firstBad||"rar-rf-first";}
  if(!last){err("last","Required.");ok=false;firstBad=firstBad||"rar-rf-last";}
  if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){err("email","Enter a valid email.");ok=false;firstBad=firstBad||"rar-rf-email";}
  else if(!domainOk(email)){err("email","Use an "+domainsPhrase()+" email address.");ok=false;firstBad=firstBad||"rar-rf-email";}
  if(!ok){if(firstBad){document.getElementById(firstBad).focus();}return;}
  var fd=new FormData();
  fd.append("_wpnonce",CFG.nonce);fd.append("rar_hp",(formEl.querySelector('[name=rar_hp]')||{}).value||"");
  fd.append("room_id",roomId);fd.append("date",AVAIL.date);fd.append("start",start);
  fd.append("duration",dur);fd.append("first",first);fd.append("last",last);fd.append("email",email);
  var btn=formEl.querySelector(".go");btn.disabled=true;btn.textContent="Reserving…";
  fetch(CFG.reserveUrl,{method:"POST",body:fd}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})
    .then(function(res){
      if(!res.ok)throw new Error(res.j&&res.j.message?res.j.message:"Could not reserve.");
      formEl.innerHTML='<div class="note" role="status" aria-live="polite" tabindex="-1">✓ Reserved! A confirmation email is on its way with a cancel link.</div>';
      var n=formEl.querySelector(".note");if(n)n.focus();
      curOpen=null;
      load(AVAIL.date);
    }).catch(function(e){
      btn.disabled=false;btn.textContent="Reserve";
      var top=formEl.querySelector(".note");if(top)top.innerHTML='<strong>'+esc(e.message)+'</strong>';
      if(/just booked/i.test(e.message)){load(AVAIL.date);}
    });
}
})();
</script>
HTML;
}
