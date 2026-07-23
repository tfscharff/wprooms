<?php
/**
 * Plugin Name:       MCW Reserve a Room
 * Description:        No-code study-room booking: staff manage rooms and rules under Reserve a Room in wp-admin; patrons book instantly with [reserve_a_room]. Bookable hours come from the Library Hours plugin. Replaces LibCal Spaces.
 * Version:           1.0.7
 * Author:            Madeleine Clark Wallace Library
 * License:           GPL-2.0+
 * Requires at least: 5.6
 * Requires PHP:      7.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MCW_ROOMS_OPTION', 'mcw_rooms' );
define( 'MCW_ROOMS_VERSION', '1.0.0' );
define( 'MCW_ROOMS_DB_VERSION', '1' );
define( 'MCW_ROOMS_REPLY_TO', 'library@wheatoncollege.edu' );

function mcw_rooms_table() {
	global $wpdb;
	return $wpdb->prefix . 'mcw_room_slots';
}

register_activation_hook( __FILE__, 'mcw_rooms_install' );

function mcw_rooms_install() {
	global $wpdb;
	$table   = mcw_rooms_table();
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
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'mcw_rooms_db_version', MCW_ROOMS_DB_VERSION );
}

/* ------------------------------------------------------------------ *
 *  Rooms + rules config
 * ------------------------------------------------------------------ */

function mcw_rooms_defaults() {
	return array(
		'rules' => array( 'slotMin' => 30, 'maxMin' => 120, 'advanceDays' => 14, 'dailyMaxMin' => 0 ),
		'rooms' => array(
			array( 'id' => 'r1', 'name' => 'Study Room 1', 'capacity' => 4, 'notes' => '' ),
			array( 'id' => 'r2', 'name' => 'Study Room 2', 'capacity' => 4, 'notes' => '' ),
		),
	);
}

function mcw_rooms_get() {
	$v = get_option( MCW_ROOMS_OPTION );
	if ( empty( $v ) || ! is_array( $v ) ) { $v = mcw_rooms_defaults(); }
	if ( ! isset( $v['rules'] ) || ! is_array( $v['rules'] ) ) {
		$d           = mcw_rooms_defaults();
		$v['rules']  = $d['rules'];
	}
	if ( ! isset( $v['rooms'] ) || ! is_array( $v['rooms'] ) ) { $v['rooms'] = array(); }
	// Fill in rules added after the option was first stored.
	$d = mcw_rooms_defaults();
	foreach ( $d['rules'] as $k => $def ) {
		if ( ! isset( $v['rules'][ $k ] ) ) { $v['rules'][ $k ] = $def; }
	}
	return $v;
}

/** Generate a stable, unique room id. Must be collision-proof even when many rooms are saved at once. */
function mcw_rooms_new_id() {
	if ( function_exists( 'random_bytes' ) ) {
		return 'r' . bin2hex( random_bytes( 6 ) );
	}
	return 'r' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
}

function mcw_rooms_sanitize( $in ) {
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
			do { $id = mcw_rooms_new_id(); } while ( isset( $used[ $id ] ) );
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

/* ------------------------------------------------------------------ *
 *  Time / hours / slot helpers
 * ------------------------------------------------------------------ */

function mcw_room_hhmm2min( $t ) {
	$a = explode( ':', (string) $t );
	return ( (int) $a[0] ) * 60 + ( isset( $a[1] ) ? (int) $a[1] : 0 );
}
function mcw_room_min2hhmm( $m ) {
	$m = (int) $m;
	return sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
}

function mcw_rooms_hours_option() {
	$h = get_option( 'mcw_library_hours' );
	return is_array( $h ) ? $h : null;
}

function mcw_rooms_tz() {
	$h  = mcw_rooms_hours_option();
	$tz = ( $h && ! empty( $h['timezone'] ) ) ? $h['timezone'] : 'America/New_York';
	return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : 'America/New_York';
}

function mcw_rooms_now() {
	return new DateTime( 'now', new DateTimeZone( mcw_rooms_tz() ) );
}

/** Keep only well-formed {open,close} blocks from a day's list. */
function mcw_room_normalize_blocks( $blocks ) {
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
function mcw_room_open_blocks( $date ) {
	$h = mcw_rooms_hours_option();
	if ( ! $h ) { return array(); }

	// 1. A single-date exception overrides everything.
	$exc = isset( $h['exceptions'] ) && is_array( $h['exceptions'] ) ? $h['exceptions'] : array();
	if ( isset( $exc[ $date ] ) && is_array( $exc[ $date ] ) ) {
		$e = $exc[ $date ];
		if ( isset( $e['closed'] ) && $e['closed'] ) { return array(); }
		return mcw_room_normalize_blocks( $e );
	}

	$dow = (string) (int) ( new DateTime( $date, new DateTimeZone( mcw_rooms_tz() ) ) )->format( 'w' );

	// 2. The first term range covering this date overrides the weekly default.
	$ranges = isset( $h['ranges'] ) && is_array( $h['ranges'] ) ? $h['ranges'] : array();
	foreach ( $ranges as $r ) {
		if ( ! is_array( $r ) || empty( $r['start'] ) || empty( $r['end'] ) ) { continue; }
		if ( $date >= $r['start'] && $date <= $r['end'] ) {
			$rw = isset( $r['weekly'] ) && is_array( $r['weekly'] ) ? $r['weekly'] : array();
			return mcw_room_normalize_blocks( isset( $rw[ $dow ] ) ? $rw[ $dow ] : array() );
		}
	}

	// 3. Weekly default.
	$weekly = isset( $h['weekly'] ) && is_array( $h['weekly'] ) ? $h['weekly'] : array();
	return mcw_room_normalize_blocks( isset( $weekly[ $dow ] ) ? $weekly[ $dow ] : array() );
}

/**
 * Candidate 30-min slot starts ('HH:MM') that fit fully inside an open block.
 * A slot occupies [start, start+30); the last slot in a block ends exactly at close.
 */
function mcw_room_candidate_slots( $date ) {
	$slots = array();
	foreach ( mcw_room_open_blocks( $date ) as $b ) {
		$o = mcw_room_hhmm2min( $b['open'] );
		$c = mcw_room_hhmm2min( $b['close'] );          // "24:00" => 1440
		for ( $m = $o; $m + 30 <= $c; $m += 30 ) {
			$slots[] = mcw_room_min2hhmm( $m );
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
function mcw_rooms_taken_for_date( $date ) {
	global $wpdb;
	$table = mcw_rooms_table();
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
	register_rest_route( 'mcw-rooms/v1', '/config', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$cfg = mcw_rooms_get();
			return rest_ensure_response( array(
				'rules'      => $cfg['rules'],
				'rooms'      => array_map( function ( $r ) {
					return array( 'id' => $r['id'], 'name' => $r['name'], 'capacity' => $r['capacity'], 'notes' => $r['notes'] );
				}, $cfg['rooms'] ),
				'hoursReady' => (bool) mcw_rooms_hours_option(),
			) );
		},
	) );

	register_rest_route( 'mcw-rooms/v1', '/availability', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'date' => array( 'required' => true ) ),
		'callback'            => 'mcw_rooms_availability',
	) );

	register_rest_route( 'mcw-rooms/v1', '/reserve', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // patrons are not logged in; hardened in the handler
		'callback'            => 'mcw_rooms_reserve',
	) );

	register_rest_route( 'mcw-rooms/v1', '/config', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
		'callback'            => function ( WP_REST_Request $req ) {
			$body = $req->get_json_params();
			if ( ! is_array( $body ) || ! isset( $body['rooms'] ) ) {
				return new WP_Error( 'mcw_bad', 'Invalid data.', array( 'status' => 400 ) );
			}
			$clean = mcw_rooms_sanitize( $body );
			update_option( MCW_ROOMS_OPTION, $clean );
			// Echo the sanitized rules back so the editor shows what was actually stored.
			return rest_ensure_response( array( 'ok' => true, 'rules' => $clean['rules'] ) );
		},
	) );
} );

function mcw_rooms_availability( WP_REST_Request $req ) {
	$date = (string) $req->get_param( 'date' );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return new WP_Error( 'mcw_date', 'Invalid date.', array( 'status' => 400 ) );
	}
	if ( ! mcw_rooms_hours_option() ) {
		return new WP_Error( 'mcw_hours', 'Room booking is temporarily unavailable.', array( 'status' => 503 ) );
	}
	$cfg   = mcw_rooms_get();
	$now   = mcw_rooms_now();
	$today = $now->format( 'Y-m-d' );
	$max   = ( clone $now )->modify( '+' . (int) $cfg['rules']['advanceDays'] . ' days' )->format( 'Y-m-d' );
	if ( $date < $today || $date > $max ) {
		return new WP_Error( 'mcw_window', 'That date is outside the booking window.', array( 'status' => 400 ) );
	}
	$nowMin = ( $date === $today ) ? ( (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' ) ) : null;
	return rest_ensure_response( array(
		'date'    => $date,
		'blocks'  => mcw_room_open_blocks( $date ),
		'slots'   => mcw_room_candidate_slots( $date ),
		'slotMin' => (int) $cfg['rules']['slotMin'],
		'maxMin'  => (int) $cfg['rules']['maxMin'],
		'dailyMaxMin' => (int) $cfg['rules']['dailyMaxMin'],
		'rooms'   => array_map( function ( $r ) { return array( 'id' => $r['id'], 'name' => $r['name'], 'notes' => $r['notes'], 'capacity' => $r['capacity'] ); }, $cfg['rooms'] ),
		'taken'   => mcw_rooms_taken_for_date( $date ),
		'nowMin'  => $nowMin,
	) );
}

/* ------------------------------------------------------------------ *
 *  REST: reserve (atomic, double-booking-safe)
 * ------------------------------------------------------------------ */

/** Minutes this email already has booked on $date, across all rooms. One row = 30 minutes. */
function mcw_rooms_booked_minutes_on( $email, $date ) {
	global $wpdb;
	$table = mcw_rooms_table();
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
function mcw_rooms_fmt_minutes( $min ) {
	$min = (int) $min;
	if ( $min > 0 && 0 === $min % 60 ) {
		$h = intdiv( $min, 60 );
		return $h . ( 1 === $h ? ' hour' : ' hours' );
	}
	return $min . ' minutes';
}

function mcw_rooms_daily_limit_message( $limit, $already ) {
	$left = max( 0, (int) $limit - (int) $already );
	$msg  = 'There’s a limit of ' . mcw_rooms_fmt_minutes( $limit ) . ' per person per day, and you already have '
		. mcw_rooms_fmt_minutes( $already ) . ' booked for that date.';
	$msg .= $left > 0
		? ' You can still book up to ' . mcw_rooms_fmt_minutes( $left ) . '.'
		: ' Please choose another date, or cancel an existing reservation first.';
	return $msg;
}

function mcw_rooms_reserve( WP_REST_Request $req ) {
	global $wpdb;

	// 1. Anti-bot.
	if ( ! wp_verify_nonce( $req->get_param( '_wpnonce' ), 'wp_rest' ) ) {
		return new WP_Error( 'mcw_nonce', 'Your session expired. Please reload the page and try again.', array( 'status' => 403 ) );
	}
	if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) {
		return new WP_Error( 'mcw_bot', 'Submission blocked.', array( 'status' => 400 ) );
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
		return new WP_Error( 'mcw_name', 'Please enter your first and last name.', array( 'status' => 400 ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'mcw_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) {
		return new WP_Error( 'mcw_input', 'Invalid reservation details.', array( 'status' => 400 ) );
	}

	// 3. Room + rules.
	$cfg  = mcw_rooms_get();
	$room = null;
	foreach ( $cfg['rooms'] as $r ) { if ( $r['id'] === $room_id ) { $room = $r; break; } }
	if ( ! $room ) {
		return new WP_Error( 'mcw_room', 'That room is not available.', array( 'status' => 400 ) );
	}
	$maxMin = (int) $cfg['rules']['maxMin'];
	if ( $duration < 30 || $duration > $maxMin || 0 !== $duration % 30 ) {
		return new WP_Error( 'mcw_dur', 'Please choose a valid length.', array( 'status' => 400 ) );
	}

	// 4. Date within window.
	$now   = mcw_rooms_now();
	$today = $now->format( 'Y-m-d' );
	$max   = ( clone $now )->modify( '+' . (int) $cfg['rules']['advanceDays'] . ' days' )->format( 'Y-m-d' );
	if ( $date < $today || $date > $max ) {
		return new WP_Error( 'mcw_window', 'That date is outside the booking window.', array( 'status' => 400 ) );
	}

	// 5. Every needed slot must be an in-hours candidate (guarantees within hours + no gap-spanning),
	//    and not in the past.
	$candidates = mcw_room_candidate_slots( $date );
	$startMin   = mcw_room_hhmm2min( $start );
	$nowMin     = ( (int) $now->format( 'G' ) ) * 60 + (int) $now->format( 'i' );
	$needed     = array();
	for ( $m = $startMin; $m < $startMin + $duration; $m += 30 ) {
		$hhmm = mcw_room_min2hhmm( $m );
		if ( ! in_array( $hhmm, $candidates, true ) ) {
			return new WP_Error( 'mcw_hours', 'That time is outside the room’s available hours.', array( 'status' => 400 ) );
		}
		if ( $date === $today && $m < $nowMin ) {
			return new WP_Error( 'mcw_past', 'That time has already passed.', array( 'status' => 400 ) );
		}
		$needed[] = $hhmm;
	}

	// 5b. Daily limit per person (0 = no limit). Counted across all rooms for this email on this date.
	$dailyMax = (int) $cfg['rules']['dailyMaxMin'];
	if ( $dailyMax > 0 ) {
		$already = mcw_rooms_booked_minutes_on( $email, $date );
		if ( $already + $duration > $dailyMax ) {
			return new WP_Error(
				'mcw_daily',
				mcw_rooms_daily_limit_message( $dailyMax, $already ),
				array( 'status' => 400 )
			);
		}
	}

	// 6. Atomic multi-row insert. UNIQUE(room_id, slot_start) blocks any conflict, even concurrent ones.
	$token   = wp_generate_password( 32, false );
	$created = current_time( 'mysql' );
	$table   = mcw_rooms_table();
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
			return new WP_Error( 'mcw_taken', 'Sorry, that time was just booked. Please pick another slot.', array( 'status' => 409 ) );
		}
		return new WP_Error( 'mcw_db', 'Something went wrong saving your reservation. Please try again.', array( 'status' => 500 ) );
	}

	// 6b. Re-check the daily limit now that the rows exist, so two simultaneous requests can't both
	//     squeak past the pre-check. If this booking put the person over, undo it.
	if ( $dailyMax > 0 ) {
		$total = mcw_rooms_booked_minutes_on( $email, $date );
		if ( $total > $dailyMax ) {
			$wpdb->delete( $table, array( 'booking_id' => $token ), array( '%s' ) );
			return new WP_Error(
				'mcw_daily',
				mcw_rooms_daily_limit_message( $dailyMax, $total - $duration ),
				array( 'status' => 400 )
			);
		}
	}

	// 7. Confirmation email (best-effort).
	$endHHMM = mcw_room_min2hhmm( $startMin + $duration );
	mcw_rooms_email_confirm( $email, $room['name'], $date, $start, $endHHMM, $token );

	return rest_ensure_response( array( 'ok' => true, 'token' => $token ) );
}

function mcw_rooms_email_confirm( $to, $room_name, $date, $startHHMM, $endHHMM, $token ) {
	$cancel  = add_query_arg( 'mcw_cancel', $token, home_url( '/' ) );
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
	$headers = array( 'Reply-To: ' . MCW_ROOMS_REPLY_TO );
	if ( ! wp_mail( $to, $subject, implode( "\n", $lines ), $headers ) ) {
		error_log( 'mcw-reserve-a-room: confirmation email failed for ' . $to );
	}
}

/* ------------------------------------------------------------------ *
 *  Cancellation (tokenized link from the confirmation email)
 * ------------------------------------------------------------------ */

function mcw_rooms_cancel_booking( $token ) {
	global $wpdb;
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
	if ( 32 !== strlen( $token ) ) { return 0; }
	$table = mcw_rooms_table();
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE booking_id = %s", $token ) );
}

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['mcw_cancel'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
	$deleted = mcw_rooms_cancel_booking( wp_unslash( $_GET['mcw_cancel'] ) ); // phpcs:ignore WordPress.Security
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
		'mcw-reserve-a-room', 'mcw_rooms_admin_page', 'dashicons-calendar-alt', 32
	);
	// After the editor so the editor stays the default submenu (Piece 2 menu-order lesson).
	add_submenu_page(
		'mcw-reserve-a-room', 'Reservations', 'Reservations', 'edit_pages',
		'mcw-room-reservations', 'mcw_rooms_reservations_page'
	);
} );

function mcw_rooms_admin_page() {
	$root  = esc_url_raw( rest_url( 'mcw-rooms/v1/config' ) );
	$nonce = wp_create_nonce( 'wp_rest' );
	echo '<div class="wrap"><h1>Reserve a Room</h1>';
	echo '<p style="max-width:680px;color:#555">Add each bookable room and set the rules, then click <strong>Save &amp; publish</strong>. Bookable hours come automatically from <strong>Library Hours</strong> — rooms can only be booked when the library is open. Show the booking grid on any page with <code>[reserve_a_room]</code>.</p>';
	echo '<script>window.MCW_ROOMS=' . wp_json_encode( array( 'root' => $root, 'nonce' => $nonce ) ) . ';</script>';
	echo mcw_rooms_editor_markup(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

/** The editor UI + JS. Nowdoc => no PHP interpolation of $ in JS. */
function mcw_rooms_editor_markup() {
	return <<<'HTML'
<style>
  #mcwrm{--accent:#00539b;--line:#dcdcdc;--muted:#666;max-width:820px;font-size:14px}
  #mcwrm .card{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 16px;margin:14px 0;position:relative}
  #mcwrm .grid{display:grid;grid-template-columns:2fr 1fr;gap:10px 14px}
  #mcwrm .full{grid-column:1/3}
  #mcwrm label{display:block;font-weight:600;font-size:.82rem;color:var(--muted);margin-bottom:3px}
  #mcwrm input[type=text],#mcwrm input[type=number]{width:100%;font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;box-sizing:border-box}
  #mcwrm .num{font-weight:700;color:var(--accent);margin-bottom:6px}
  #mcwrm .b{cursor:pointer;border:1px solid var(--accent);background:var(--accent);color:#fff;padding:8px 14px;border-radius:7px;font-weight:600;font-size:.9rem}
  #mcwrm .b.sec{background:#fff;color:var(--accent)}
  #mcwrm .b.ghost{background:#fff;color:var(--muted);border-color:var(--line)}
  #mcwrm .b.tiny{padding:3px 9px;font-size:.8rem;border-radius:5px;position:absolute;top:12px;right:14px}
  #mcwrm .rules{display:flex;gap:16px;flex-wrap:wrap;align-items:end}
  #mcwrm .rules label{font-weight:600;color:#1a1a1a}
  #mcwrm .rules input{width:90px}
  #mcwrm .bar{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:8px}
  #mcwrm .status{font-weight:600}
  #mcwrm .hint{font-size:.82rem;color:var(--muted);margin:6px 0 0}
</style>
<div id="mcwrm">
  <div class="card">
    <h2>Rules</h2>
    <div class="rules">
      <label>Max length (minutes)<br><input type="number" id="mcwrm-max" min="30" step="30"></label>
      <label>Book ahead (days)<br><input type="number" id="mcwrm-adv" min="1" max="90"></label>
      <label>Daily limit per person (minutes)<br><input type="number" id="mcwrm-daily" min="0" max="480" step="30"></label>
    </div>
    <p class="hint">Slots are 30 minutes. Max length must be a multiple of 30.</p>
    <p class="hint"><strong>Daily limit</strong> is the total a single person (by email) can book across
      all rooms on one day. Enter <strong>0</strong> for no limit. It can't be lower than the max length
      above — if you enter less, it's raised to match.</p>
  </div>
  <div id="mcwrm-rooms"></div>
  <div class="bar"><button type="button" class="b sec" id="mcwrm-add">+ Add a room</button></div>
  <div class="bar"><button type="button" class="b" id="mcwrm-save">Save &amp; publish</button><span class="status" id="mcwrm-status"></span></div>
</div>
<script>
(function(){
"use strict";
var data={rules:{slotMin:30,maxMin:120,advanceDays:14,dailyMaxMin:0},rooms:[]};
function el(t,c){var e=document.createElement(t);if(c)e.className=c;return e;}
function renderRooms(){
  var wrap=document.getElementById("mcwrm-rooms");wrap.innerHTML="";
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
  document.getElementById("mcwrm-max").value=data.rules.maxMin;
  document.getElementById("mcwrm-adv").value=data.rules.advanceDays;
  document.getElementById("mcwrm-daily").value=data.rules.dailyMaxMin||0;
}
function flash(m,bad){var s=document.getElementById("mcwrm-status");s.textContent=m;s.style.color=bad?"#b42318":"#1a7f37";}
document.getElementById("mcwrm-add").onclick=function(){data.rooms.push({id:"",name:"",capacity:4,notes:""});renderRooms();};
document.getElementById("mcwrm-save").onclick=function(){
  data.rules.maxMin=parseInt(document.getElementById("mcwrm-max").value||"120",10);
  data.rules.advanceDays=parseInt(document.getElementById("mcwrm-adv").value||"14",10);
  data.rules.dailyMaxMin=parseInt(document.getElementById("mcwrm-daily").value||"0",10);
  if(!(data.rules.dailyMaxMin>0))data.rules.dailyMaxMin=0;
  flash("Saving…");
  fetch(MCW_ROOMS.root,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":MCW_ROOMS.nonce},body:JSON.stringify(data)})
    .then(function(r){if(!r.ok)throw 0;return r.json();})
    .then(function(d){if(d&&d.rules){data.rules=d.rules;fillRules();}flash("Saved — it's live. ✓");})
    .catch(function(){flash("Save failed — please try again or reload.",true);});
};
fetch(MCW_ROOMS.root).then(function(r){return r.json();}).then(function(d){
  data.rooms=(d.rooms||[]).map(function(x){return{id:x.id,name:x.name,capacity:x.capacity,notes:x.notes};});
  data.rules=d.rules||data.rules;
  fillRules();
  renderRooms();
}).catch(function(){renderRooms();});
})();
</script>
HTML;
}

function mcw_rooms_reservations_page() {
	global $wpdb;
	// Handle a staff cancel (nonce-protected form POST).
	if ( isset( $_POST['mcw_cancel_token'], $_POST['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'mcw_rooms_cancel' )
		&& current_user_can( 'edit_pages' ) ) {
		$n = mcw_rooms_cancel_booking( sanitize_text_field( wp_unslash( $_POST['mcw_cancel_token'] ) ) );
		echo '<div class="notice notice-success"><p>' . ( $n > 0 ? 'Reservation cancelled.' : 'Nothing to cancel.' ) . '</p></div>';
	}
	$table = mcw_rooms_table();
	$now   = current_time( 'mysql' );
	// One row per booking: min/max slot = start/end, +30 min on the last slot for the true end.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT booking_id, room_id, first, last, email,
		        MIN(slot_start) AS start_at, MAX(slot_start) AS last_slot, COUNT(*) AS slots
		 FROM {$table} WHERE slot_start >= %s
		 GROUP BY booking_id, room_id, first, last, email
		 ORDER BY start_at ASC", $now
	) );
	$cfg   = mcw_rooms_get();
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
			. wp_nonce_field( 'mcw_rooms_cancel', '_wpnonce', true, false )
			. '<input type="hidden" name="mcw_cancel_token" value="' . esc_attr( $r->booking_id ) . '">'
			. '<button class="button button-small">Cancel</button></form></td></tr>';
	}
	echo '</tbody></table></div>';
}

/* ------------------------------------------------------------------ *
 *  Front-end  [reserve_a_room]
 * ------------------------------------------------------------------ */

add_shortcode( 'reserve_a_room', 'mcw_rooms_shortcode' );

function mcw_rooms_shortcode( $atts ) {
	$cfg = array(
		'availUrl'   => esc_url_raw( rest_url( 'mcw-rooms/v1/availability' ) ),
		'configUrl'  => esc_url_raw( rest_url( 'mcw-rooms/v1/config' ) ),
		'reserveUrl' => esc_url_raw( rest_url( 'mcw-rooms/v1/reserve' ) ),
		'nonce'      => wp_create_nonce( 'wp_rest' ),
	);
	$config_js = '<script>window.MCW_ROOMS_CFG=' . wp_json_encode( $cfg ) . ';</script>';
	return mcw_rooms_widget_markup() . $config_js . mcw_rooms_widget_script();
}

function mcw_rooms_widget_markup() {
	return <<<'HTML'
<div id="mcw-rooms" class="mcw-rooms" aria-live="polite">
  <div class="mcw-rooms__bar">
    <label>Date <input type="date" id="mcw-rooms-date"></label>
  </div>
  <div id="mcw-rooms-grid"><p class="mcw-rooms__note">Loading…</p></div>
  <div id="mcw-rooms-form"></div>
</div>
<style>
  #mcw-rooms{--accent:#00539b;--line:#dcdcdc;--grid:#8b9198;--muted:#666;--free:#bfe6cb;--freeb:#1a7f37;--taken:#c9cccf;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;max-width:100%}
  #mcw-rooms .mcw-rooms__bar{margin:0 0 12px;font-weight:600}
  #mcw-rooms input[type=date]{font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px}
  #mcw-rooms .mcw-rooms__scroll{overflow-x:auto}
  #mcw-rooms table{border-collapse:collapse;font-size:.8rem}
  #mcw-rooms th,#mcw-rooms td{border:1px solid var(--grid);padding:0}
  #mcw-rooms table{border:1px solid var(--grid)}
  #mcw-rooms th{background:#f6f8fa;padding:4px 6px;white-space:nowrap;font-weight:600;min-width:52px}
  #mcw-rooms th.mcw-rooms__room{text-align:left;position:sticky;left:0;z-index:2;background:#f6f8fa;min-width:150px}
  #mcw-rooms th.mcw-rooms__rowhead{text-align:left;position:sticky;left:0;z-index:1;background:#fff;font-weight:600;padding:4px 6px;white-space:nowrap;min-width:150px}
  #mcw-rooms .mcw-rooms__rhwrap{display:flex;align-items:center;gap:8px}
  #mcw-rooms .mcw-rooms__info{margin-left:auto;flex:0 0 auto;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:var(--accent);color:#fff;font-size:11px;font-weight:700;font-style:normal;cursor:help}
  #mcw-rooms .mcw-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
  #mcw-rooms .slot{width:100%;min-width:52px;height:34px;display:block}
  #mcw-rooms .slot.free{background:var(--free);cursor:pointer}
  #mcw-rooms .slot.free:hover{background:var(--freeb)}
  #mcw-rooms .slot.free:focus{outline:3px solid #003b71;outline-offset:-3px;background:var(--freeb)}
  #mcw-rooms .slot.taken{background:var(--taken);background-image:repeating-linear-gradient(45deg,transparent,transparent 4px,rgba(0,0,0,.06) 4px,rgba(0,0,0,.06) 8px)}
  #mcw-rooms .mcw-rooms__note{color:var(--muted);padding:8px 0}
  #mcw-rooms .mcw-rooms__legend{font-size:.8rem;color:var(--muted);margin-top:8px}
  #mcw-rooms .mcw-rooms__kbd{margin:0 0 10px}
  #mcw-rooms .mcw-rooms__legend b{display:inline-block;width:12px;height:12px;vertical-align:-2px;border:1px solid var(--line)}
  #mcw-rooms-form{max-width:420px;margin-top:16px}
  #mcw-rooms-form .fld{margin:10px 0}
  #mcw-rooms-form label{display:block;font-weight:600;margin-bottom:3px}
  #mcw-rooms-form input,#mcw-rooms-form select{width:100%;font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:6px;box-sizing:border-box}
  #mcw-rooms-form .err{color:#b42318;font-size:.85rem;margin-top:3px}
  #mcw-rooms-form .go{background:var(--accent);color:#fff;border:0;padding:10px 18px;border-radius:7px;font-weight:600;cursor:pointer}
  #mcw-rooms-form .cancel{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;margin-left:10px}
  #mcw-rooms-form .note{background:#eef4fb;border:1px solid #cfe0f2;border-radius:8px;padding:12px 14px;margin-bottom:14px}
</style>
HTML;
}

function mcw_rooms_widget_script() {
	return <<<'HTML'
<script>
(function(){
"use strict";
var CFG=window.MCW_ROOMS_CFG, root=document.getElementById("mcw-rooms");
if(!root||!CFG)return;
var gridEl=document.getElementById("mcw-rooms-grid"), formEl=document.getElementById("mcw-rooms-form");
var dateEl=document.getElementById("mcw-rooms-date");
var AVAIL=null;
function esc(x){return String(x==null?"":x).replace(/[&<>"']/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];});}
function pad(n){return n<10?"0"+n:""+n;}
function todayStr(){var d=new Date();return d.getFullYear()+"-"+pad(d.getMonth()+1)+"-"+pad(d.getDate());}
function m2hhmm(m){return pad(Math.floor(m/60))+":"+pad(m%60);}
function hhmm2m(s){var a=s.split(":");return (+a[0])*60+(+a[1]);}
function fmtMins(m){m=+m;if(m>0&&m%60===0){var h=m/60;return h+(h===1?" hour":" hours");}return m+" minutes";}
fetch(CFG.configUrl).then(function(r){return r.json();}).then(function(c){
  var adv=(c.rules&&c.rules.advanceDays)||14;
  var t=todayStr();dateEl.min=t;dateEl.value=t;
  var max=new Date();max.setDate(max.getDate()+adv);dateEl.max=max.getFullYear()+"-"+pad(max.getMonth()+1)+"-"+pad(max.getDate());
  load(t);
}).catch(function(){gridEl.innerHTML='<p class="mcw-rooms__note">Room booking is temporarily unavailable.</p>';});
dateEl.addEventListener("change",function(){formEl.innerHTML="";load(dateEl.value);});
function load(date){
  formEl.innerHTML="";gridEl.innerHTML='<p class="mcw-rooms__note">Loading…</p>';
  var url=CFG.availUrl+(CFG.availUrl.indexOf("?")>-1?"&":"?")+"date="+encodeURIComponent(date)+"&v="+Date.now();
  fetch(url,{cache:"no-cache"}).then(function(r){if(!r.ok)return r.json().then(function(j){throw new Error(j.message||"Unavailable");});return r.json();})
    .then(function(a){AVAIL=a;renderGrid();}).catch(function(e){gridEl.innerHTML='<p class="mcw-rooms__note">'+esc(e.message)+'</p>';});
}
function renderGrid(){
  var a=AVAIL;
  if(!a.slots||a.slots.length===0){gridEl.innerHTML='<p class="mcw-rooms__note">The library is closed on this date — no rooms are bookable.</p>';return;}
  if(!a.rooms||a.rooms.length===0){gridEl.innerHTML='<p class="mcw-rooms__note">No rooms are set up yet.</p>';return;}
  var cols=a.slots, COLS=cols.length, ROWS=a.rooms.length;
  var html='<p class="mcw-rooms__legend mcw-rooms__kbd">Keyboard: press Tab to reach the grid, use the arrow keys to move between available times, then press Enter to book.</p>'
    +'<div class="mcw-rooms__scroll"><table>'
    +'<caption class="mcw-sr">Room availability for '+esc(a.date)+'. Use the arrow keys to move between available times and press Enter to book.</caption>'
    +'<thead><tr><th scope="col" class="mcw-rooms__room">Room</th>';
  cols.forEach(function(s){html+='<th scope="col">'+esc(s)+'</th>';});
  html+='</tr></thead><tbody>';
  a.rooms.forEach(function(room,ri){
    var taken=(a.taken&&a.taken[room.id])||[];
    var info=room.notes?'<span class="mcw-rooms__info" role="img" aria-label="Note: '+esc(room.notes)+'" title="'+esc(room.notes)+'">i</span>':'';
    html+='<tr><th scope="row" class="mcw-rooms__rowhead"><span class="mcw-rooms__rhwrap"><span>'+esc(room.name)+'</span>'+info+'</span></th>';
    cols.forEach(function(s,ci){
      var isTaken=taken.indexOf(s)>-1;
      var past=(a.nowMin!=null)&&(hhmm2m(s)<a.nowMin);
      if(isTaken||past){html+='<td><span class="slot taken" title="'+(isTaken?"Booked":"Past")+'"></span></td>';}
      else{html+='<td><span class="slot free" role="button" tabindex="-1" data-ri="'+ri+'" data-ci="'+ci+'" data-room="'+esc(room.id)+'" data-start="'+esc(s)+'" aria-label="Book '+esc(room.name)+' at '+esc(s)+', '+esc(a.date)+'"></span></td>';}
    });
    html+='</tr>';
  });
  html+='</tbody></table></div>'
    +'<p class="mcw-rooms__legend"><b style="background:#bfe6cb"></b> <strong>Green = available</strong> — click or press Enter on a green square to book &nbsp;&nbsp; <b style="background:#c9cccf"></b> grey = unavailable</p>'
    +(a.dailyMaxMin>0?'<p class="mcw-rooms__legend">Each person may book up to '+esc(fmtMins(a.dailyMaxMin))+' per day across all rooms.</p>':'');
  gridEl.innerHTML=html;
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
function openForm(roomId,start){
  var a=AVAIL, room=null;a.rooms.forEach(function(r){if(r.id===roomId)room=r;});
  var runs=freeRun(roomId,start); if(runs<1)return;
  var opts="";for(var k=1;k<=runs;k++){var mins=k*30;opts+='<option value="'+mins+'">'+mins+' min ('+start+' – '+m2hhmm(hhmm2m(start)+mins)+')</option>';}
  formEl.innerHTML='<div class="note" role="status" aria-live="polite">Reserve <strong>'+esc(room.name)+'</strong> on '+esc(a.date)+' starting <strong>'+esc(start)+'</strong>.</div>'
    +'<form id="mcw-rf" novalidate aria-label="Reserve '+esc(room.name)+' at '+esc(start)+'">'
    +'<div class="fld"><label for="mcw-rf-dur">Length</label><select id="mcw-rf-dur">'+opts+'</select></div>'
    +'<div class="fld"><label for="mcw-rf-first">First name</label><input type="text" id="mcw-rf-first" autocomplete="given-name" aria-describedby="mcw-rf-first-e"><div class="err" id="mcw-rf-first-e" data-e="first" role="alert"></div></div>'
    +'<div class="fld"><label for="mcw-rf-last">Last name</label><input type="text" id="mcw-rf-last" autocomplete="family-name" aria-describedby="mcw-rf-last-e"><div class="err" id="mcw-rf-last-e" data-e="last" role="alert"></div></div>'
    +'<div class="fld"><label for="mcw-rf-email">Email</label><input type="email" id="mcw-rf-email" autocomplete="email" aria-describedby="mcw-rf-email-e"><div class="err" id="mcw-rf-email-e" data-e="email" role="alert"></div></div>'
    +'<input type="text" name="website" style="position:absolute;left:-9999px" tabindex="-1" autocomplete="off" aria-hidden="true">'
    +'<button type="submit" class="go">Reserve</button><button type="button" class="cancel" id="mcw-rf-cancel">Cancel</button></form>';
  formEl.scrollIntoView({behavior:"smooth",block:"start"});
  document.getElementById("mcw-rf-cancel").addEventListener("click",function(){formEl.innerHTML="";var f=gridEl.querySelector('.slot.free[tabindex="0"]');if(f)f.focus();});
  document.getElementById("mcw-rf").addEventListener("submit",function(e){e.preventDefault();submit(roomId,start);});
  document.getElementById("mcw-rf-dur").focus(); // move keyboard focus into the form
}
function err(k,m){var e=formEl.querySelector('[data-e="'+k+'"]');if(e)e.textContent=m||"";}
function submit(roomId,start){
  var first=document.getElementById("mcw-rf-first").value.trim();
  var last=document.getElementById("mcw-rf-last").value.trim();
  var email=document.getElementById("mcw-rf-email").value.trim();
  var dur=document.getElementById("mcw-rf-dur").value;
  var ok=true, firstBad=null;err("first","");err("last","");err("email","");
  if(!first){err("first","Required.");ok=false;firstBad=firstBad||"mcw-rf-first";}
  if(!last){err("last","Required.");ok=false;firstBad=firstBad||"mcw-rf-last";}
  if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)){err("email","Enter a valid email.");ok=false;firstBad=firstBad||"mcw-rf-email";}
  if(!ok){if(firstBad){document.getElementById(firstBad).focus();}return;}
  var fd=new FormData();
  fd.append("_wpnonce",CFG.nonce);fd.append("website",(formEl.querySelector('[name=website]')||{}).value||"");
  fd.append("room_id",roomId);fd.append("date",AVAIL.date);fd.append("start",start);
  fd.append("duration",dur);fd.append("first",first);fd.append("last",last);fd.append("email",email);
  var btn=formEl.querySelector(".go");btn.disabled=true;btn.textContent="Reserving…";
  fetch(CFG.reserveUrl,{method:"POST",body:fd}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j};});})
    .then(function(res){
      if(!res.ok)throw new Error(res.j&&res.j.message?res.j.message:"Could not reserve.");
      formEl.innerHTML='<div class="note" role="status" aria-live="polite" tabindex="-1">✓ Reserved! A confirmation email is on its way with a cancel link.</div>';
      var n=formEl.querySelector(".note");if(n)n.focus();
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
