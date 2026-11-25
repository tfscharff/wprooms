<?php
/** Test cases. Run through tests/run.php, which loads the WordPress stubs, a fake $wpdb and the plugin. */

$fails = 0; $passes = 0;
$quiet = in_array( '-q', isset( $argv ) ? $argv : array(), true ); // php tests/run.php -q  prints failures and the summary only
function ok( $cond, $msg ) {
	global $fails, $passes, $quiet;
	if ( $cond ) { $passes++; if ( ! $quiet ) { echo "PASS: $msg\n"; } } else { $fails++; echo "FAIL: $msg\n"; }
}
function eq( $expected, $actual, $msg ) {
	global $fails, $passes, $quiet;
	if ( $actual === $expected ) { $passes++; if ( ! $quiet ) { echo "PASS: $msg\n"; } return; }
	$fails++;
	echo "FAIL: $msg\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}
function is_err( $r, $code, $status, $msg ) {
	$got = $r instanceof WP_Error ? $r->get_error_code() . '/' . $r->get_error_data()['status'] : 'not an error';
	eq( $code . '/' . $status, $got, $msg );
}

/* ---- helpers ---- */

function fresh() {
	global $wpdb;
	$GLOBALS['rar_options'] = array();
	$GLOBALS['rar_mail']    = array();
	$GLOBALS['rar_mail_ok'] = true;
	$GLOBALS['rar_can']     = true;
	$GLOBALS['rar_referer'] = array();
	$_GET = array(); $_POST = array();
	$wpdb->reset();
}
/** Same blocks every day of the week (0 = Sunday ... 6 = Saturday), plus any extra keys. */
function set_hours( $blocks, $extra = array() ) {
	$w = array();
	for ( $d = 0; $d <= 6; $d++ ) { $w[ (string) $d ] = $blocks; }
	$GLOBALS['rar_options']['mcw_library_hours'] = array_merge( array( 'timezone' => 'America/New_York', 'weekly' => $w ), $extra );
}
function day9to5() { return array( array( 'open' => '09:00', 'close' => '17:00' ) ); }
function plus_days( $n ) { return rar_now()->modify( ( $n >= 0 ? '+' : '' ) . $n . ' days' )->format( 'Y-m-d' ); }
function reserve_req( $over = array() ) {
	return new WP_REST_Request( array_merge( array(
		'_wpnonce' => 'good', 'rar_hp' => '', 'room_id' => 'r1', 'date' => plus_days( 1 ), 'start' => '10:00',
		'duration' => 60, 'first' => 'Ada', 'last' => 'Lovelace', 'email' => 'ada@wheatoncollege.edu',
	), $over ) );
}
function denied_reasons() {
	global $wpdb;
	$r = array();
	foreach ( $wpdb->inserts as $i ) { if ( 'denied' === $i[1]['kind'] ) { $r[] = $i[1]['reason']; } }
	return $r;
}
function route( $route, $method ) {
	foreach ( $GLOBALS['rar_routes'] as $r ) {
		if ( $r[1] === $route && $r[2]['methods'] === $method ) { return $r[2]; }
	}
	return null;
}
foreach ( $GLOBALS['rar_hooks']['rest_api_init'] as $cb ) { $cb(); }

/* ---------------- load-time ---------------- */

ok( 1 === preg_match( '/^\d+\.\d+\.\d+$/', RAR_VERSION ), 'RAR_VERSION is read from the plugin header as a SemVer string' );
eq( 'rar_rooms', RAR_OPTION, 'option name is unchanged (renaming breaks live sites)' );
eq( 'wp_rar_slots', rar_table(), 'slots table name' );
eq( 'wp_rar_stats', rar_stats_table(), 'stats table name' );
ok( isset( $GLOBALS['rar_shortcodes']['reserve_a_room'] ), 'shortcode [reserve_a_room] registered' );
eq( array( 'reserve-a-room/v1' ), array_values( array_unique( array_map( function ( $r ) { return $r[0]; }, $GLOBALS['rar_routes'] ) ) ), 'REST namespace is unchanged' );
eq( 4, count( $GLOBALS['rar_routes'] ), 'four REST routes registered (config GET and POST, availability, reserve)' );

/* ---------------- defaults and rar_get ---------------- */

fresh();
$d = rar_defaults();
eq( array( 'slotMin', 'maxMin', 'advanceDays', 'dailyMaxMin', 'timeFormat', 'emailDomains' ), array_keys( $d['rules'] ), 'default rule keys' );
eq( 2, count( $d['rooms'] ), 'two default rooms' );
eq( $d, rar_get(), 'missing option reads as defaults' );
$GLOBALS['rar_options']['rar_rooms'] = 'junk';
eq( $d, rar_get(), 'string option reads as defaults' );
$GLOBALS['rar_options']['rar_rooms'] = array();
eq( $d, rar_get(), 'empty array option reads as defaults' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rooms' => array( array( 'id' => 'a', 'name' => 'A' ) ) );
eq( $d['rules'], rar_get()['rules'], 'missing rules back-filled from defaults' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => 'junk', 'rooms' => array() );
eq( $d['rules'], rar_get()['rules'], 'non-array rules replaced by defaults' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => array( 'maxMin' => 60 ) );
$g = rar_get();
eq( array(), $g['rooms'], 'missing rooms becomes an empty list (not the default rooms)' );
eq( 60, $g['rules']['maxMin'], 'stored rule kept' );
eq( 14, $g['rules']['advanceDays'], 'rule added after first save is back-filled (advanceDays)' );
eq( array(), $g['rules']['emailDomains'], 'rule added after first save is back-filled (emailDomains)' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => array(), 'rooms' => 'junk' );
eq( array(), rar_get()['rooms'], 'non-array rooms becomes an empty list' );

/* ---------------- rar_sanitize: rules ---------------- */

$s = rar_sanitize( 'junk' );
eq( array( 'rules', 'rooms' ), array_keys( $s ), 'junk input: only rules and rooms keys' );
eq( array(), $s['rooms'], 'junk input: no rooms' );
eq( array( 'slotMin' => 30, 'maxMin' => 120, 'advanceDays' => 14, 'dailyMaxMin' => 0, 'timeFormat' => '24', 'emailDomains' => array() ), $s['rules'], 'junk input: default rules' );
eq( $s, rar_sanitize( null ), 'null input same as junk' );
eq( $s, rar_sanitize( array( 'rules' => 'x', 'rooms' => 'y' ) ), 'non-array rules and rooms ignored' );
eq( 30, rar_sanitize( array( 'rules' => array( 'slotMin' => 15 ) ) )['rules']['slotMin'], 'slotMin is fixed at 30' );
eq( array( 'slotMin', 'maxMin', 'advanceDays', 'dailyMaxMin', 'timeFormat', 'emailDomains' ), array_keys( rar_sanitize( array( 'rules' => array( 'evil' => 1 ) ) )['rules'] ), 'unknown rule keys dropped' );

$rules = function ( $r ) { return rar_sanitize( array( 'rules' => $r ) )['rules']; };
eq( 30, $rules( array( 'maxMin' => 0 ) )['maxMin'], 'maxMin 0 raised to 30' );
eq( 30, $rules( array( 'maxMin' => -50 ) )['maxMin'], 'negative maxMin raised to 30' );
eq( 480, $rules( array( 'maxMin' => 9999 ) )['maxMin'], 'maxMin capped at 480' );
eq( 60, $rules( array( 'maxMin' => 45 ) )['maxMin'], 'maxMin 45 rounds to 60' );
eq( 30, $rules( array( 'maxMin' => 44 ) )['maxMin'], 'maxMin 44 rounds to 30' );
eq( 90, $rules( array( 'maxMin' => '90' ) )['maxMin'], 'maxMin numeric string accepted' );
eq( 30, $rules( array( 'maxMin' => 'abc' ) )['maxMin'], 'maxMin non-numeric string becomes 0 then 30' );
eq( 1, $rules( array( 'advanceDays' => 0 ) )['advanceDays'], 'advanceDays raised to 1' );
eq( 90, $rules( array( 'advanceDays' => 500 ) )['advanceDays'], 'advanceDays capped at 90' );
eq( 14, $rules( array() )['advanceDays'], 'advanceDays default 14' );
eq( 0, $rules( array( 'dailyMaxMin' => 0 ) )['dailyMaxMin'], 'daily limit 0 means no limit' );
eq( 0, $rules( array( 'dailyMaxMin' => -30 ) )['dailyMaxMin'], 'negative daily limit means no limit' );
eq( 0, $rules( array( 'dailyMaxMin' => 'x' ) )['dailyMaxMin'], 'junk daily limit means no limit' );
eq( 120, $rules( array( 'dailyMaxMin' => 10 ) )['dailyMaxMin'], 'daily limit below max length is raised to max length (default 120)' );
eq( 480, $rules( array( 'dailyMaxMin' => 9999 ) )['dailyMaxMin'], 'daily limit capped at 480' );
eq( 240, $rules( array( 'dailyMaxMin' => 240 ) )['dailyMaxMin'], 'daily limit 240 kept' );
eq( 150, $rules( array( 'dailyMaxMin' => 140 ) )['dailyMaxMin'], 'daily limit rounded to a multiple of 30' );
eq( 480, $rules( array( 'maxMin' => 480, 'dailyMaxMin' => 100 ) )['dailyMaxMin'], 'daily limit never below max length (480)' );
eq( '12', $rules( array( 'timeFormat' => '12' ) )['timeFormat'], 'timeFormat 12' );
eq( '12', $rules( array( 'timeFormat' => 12 ) )['timeFormat'], 'timeFormat int 12 accepted' );
eq( '24', $rules( array( 'timeFormat' => 'bogus' ) )['timeFormat'], 'timeFormat junk becomes 24' );
eq( array( 'a.edu', 'b.edu' ), $rules( array( 'emailDomains' => "a.edu\n@B.edu, a.edu" ) )['emailDomains'], 'emailDomains string: split, lowercased, de-duplicated' );
eq( array(), $rules( array( 'emailDomains' => 42 ) )['emailDomains'], 'emailDomains junk scalar gives none' );

/* ---------------- rar_sanitize: rooms ---------------- */

$rooms = function ( $r ) { return rar_sanitize( array( 'rooms' => $r ) )['rooms']; };
eq( array(), $rooms( array( 'str', 5, null, array( 'name' => '' ), array( 'name' => '   ' ), array( 'name' => '<b></b>' ), array() ) ), 'rooms: non-arrays and nameless rooms dropped' );
$r = $rooms( array( array( 'id' => 'r1', 'name' => '<b>Quiet</b> Room', 'capacity' => '6', 'notes' => "<i>Whiteboard</i>\nTV", 'evil' => 'x' ) ) );
eq( array( 'id', 'name', 'capacity', 'notes', 'hidden', 'hideFrom', 'hideUntil' ), array_keys( $r[0] ), 'room keys are fixed; unknown keys dropped' );
eq( 'Quiet Room', $r[0]['name'], 'room name loses tags' );
eq( 6, $r[0]['capacity'], 'capacity cast to int' );
eq( 'Whiteboard TV', $r[0]['notes'], 'notes lose tags and new lines' );
eq( 'r1', $r[0]['id'], 'existing id kept' );
eq( false, $r[0]['hidden'], 'not hidden by default' );
eq( 0, $rooms( array( array( 'name' => 'X', 'capacity' => -5 ) ) )[0]['capacity'], 'negative capacity becomes 0' );
eq( 0, $rooms( array( array( 'name' => 'X' ) ) )[0]['capacity'], 'missing capacity becomes 0' );
eq( '', $rooms( array( array( 'name' => 'X' ) ) )[0]['notes'], 'missing notes become empty' );
$r = $rooms( array( array( 'name' => 'A' ), array( 'name' => 'B', 'id' => '!!' ) ) );
ok( 1 === preg_match( '/^r[0-9a-f]{12}$/', $r[0]['id'] ) && 1 === preg_match( '/^r[0-9a-f]{12}$/', $r[1]['id'] ), 'missing or unusable id gets a fresh one' );
ok( $r[0]['id'] !== $r[1]['id'], 'fresh ids differ' );
eq( 'ab12', $rooms( array( array( 'name' => 'A', 'id' => 'ab-12_X!' ) ) )[0]['id'], 'id keeps only lowercase letters and digits' );
$r = $rooms( array( array( 'name' => 'A', 'id' => 'dup' ), array( 'name' => 'B', 'id' => 'dup' ), array( 'name' => 'C', 'id' => 'dup' ) ) );
ok( 'dup' === $r[0]['id'] && 3 === count( array_unique( array_column( $r, 'id' ) ) ), 'duplicate ids are healed: first kept, others replaced' );
$r = $rooms( array( array( 'name' => 'A', 'hidden' => 1, 'hideFrom' => '2027-01-10', 'hideUntil' => '2027-02-01' ) ) );
ok( true === $r[0]['hidden'] && '2027-01-10' === $r[0]['hideFrom'] && '2027-02-01' === $r[0]['hideUntil'], 'hidden room keeps its date range' );
$r = $rooms( array( array( 'name' => 'A', 'hidden' => '1', 'hideFrom' => '2027-03-01', 'hideUntil' => '2027-02-01' ) ) );
ok( '2027-02-01' === $r[0]['hideFrom'] && '2027-03-01' === $r[0]['hideUntil'], 'reversed hide range is swapped' );
$r = $rooms( array( array( 'name' => 'A', 'hidden' => true, 'hideFrom' => '2027-02-30', 'hideUntil' => 'tomorrow' ) ) );
ok( true === $r[0]['hidden'] && '' === $r[0]['hideFrom'] && '' === $r[0]['hideUntil'], 'invalid hide dates become blank (open-ended)' );
$r = $rooms( array( array( 'name' => 'A', 'hidden' => false, 'hideFrom' => '2027-01-10', 'hideUntil' => '2027-02-01' ) ) );
ok( false === $r[0]['hidden'] && '' === $r[0]['hideFrom'] && '' === $r[0]['hideUntil'], 'hide dates dropped when the room is not hidden' );
eq( true, $rooms( array( array( 'name' => 'A', 'hidden' => 'yes' ) ) )[0]['hidden'], 'any truthy hidden value hides the room' );
eq( false, $rooms( array( array( 'name' => 'A', 'hidden' => '0' ) ) )[0]['hidden'], 'hidden "0" does not hide' );

/* ---------------- dates, hidden rooms, public room ---------------- */

eq( '2027-02-28', rar_sanitize_date( '2027-02-28' ), 'valid date kept' );
eq( '2028-02-29', rar_sanitize_date( '2028-02-29' ), 'leap day kept in a leap year' );
foreach ( array( '2027-02-29', '2027-13-01', '2027-00-10', '27-01-01', '2027-1-1', '2027-01-01 ', ' 2027-01-01', 'tomorrow', '', null, '2027-01-01<b>' ) as $bad ) {
	eq( '', rar_sanitize_date( $bad ), 'invalid date rejected: ' . var_export( $bad, true ) );
}
$room = array( 'hidden' => false );
ok( rar_room_open_on( $room, '2027-01-01' ), 'not hidden: open' );
ok( rar_room_open_on( array(), '2027-01-01' ), 'no hidden key: open' );
$room = array( 'hidden' => true, 'hideFrom' => '', 'hideUntil' => '' );
ok( ! rar_room_open_on( $room, '2027-01-01' ), 'hidden with no range: closed every day' );
$room = array( 'hidden' => true, 'hideFrom' => '2027-01-10', 'hideUntil' => '2027-01-20' );
ok( rar_room_open_on( $room, '2027-01-09' ), 'before range: open' );
ok( ! rar_room_open_on( $room, '2027-01-10' ), 'first day of range: closed (inclusive)' );
ok( ! rar_room_open_on( $room, '2027-01-20' ), 'last day of range: closed (inclusive)' );
ok( rar_room_open_on( $room, '2027-01-21' ), 'after range: open' );
ok( ! rar_room_open_on( array( 'hidden' => true, 'hideFrom' => '2027-01-10' ), '2030-01-01' ), 'from only: closed forever after' );
ok( rar_room_open_on( array( 'hidden' => true, 'hideFrom' => '2027-01-10' ), '2027-01-09' ), 'from only: open before' );
ok( ! rar_room_open_on( array( 'hidden' => true, 'hideUntil' => '2027-01-10' ), '2020-01-01' ), 'until only: closed from the start' );
ok( rar_room_open_on( array( 'hidden' => true, 'hideUntil' => '2027-01-10' ), '2027-01-11' ), 'until only: open after' );
eq( array( 'id' => 'r9', 'name' => 'N', 'capacity' => 3, 'notes' => 'n', 'hidden' => false, 'hideFrom' => '', 'hideUntil' => '' ), rar_public_room( array( 'id' => 'r9', 'name' => 'N', 'capacity' => 3, 'notes' => 'n', 'secret' => 'x' ) ), 'public room: fixed fields, defaults for hide fields, extra keys dropped' );

/* ---------------- email domains ---------------- */

eq( array( 'wheatoncollege.edu', 'sub.example.org' ), rar_sanitize_domains( array( ' @Wheatoncollege.EDU ', 'sub.example.org', 'wheatoncollege.edu' ) ), 'domains: array normalised' );
eq( array( 'a.edu', 'b.com' ), rar_sanitize_domains( "a.edu;b.com\r\n" ), 'domains: semicolon and newline separators' );
eq( array(), rar_sanitize_domains( array( 'localhost', '-bad.com', 'bad-.com', 'a..b', '.com', 'a b.com', '','http://a.edu', '<b>a.edu' ) ), 'domains: invalid entries dropped' );
eq( array(), rar_sanitize_domains( null ), 'domains: null gives none' );
eq( array(), rar_sanitize_domains( '' ), 'domains: empty string gives none' );
ok( rar_email_domain_allowed( 'x@anything.com', array() ), 'no domain list: anyone allowed' );
ok( rar_email_domain_allowed( 'a@wheatoncollege.edu', array( 'wheatoncollege.edu' ) ), 'exact domain allowed' );
ok( rar_email_domain_allowed( 'a@Mail.WheatonCollege.EDU', array( 'wheatoncollege.edu' ) ), 'subdomain allowed, case-insensitive' );
ok( ! rar_email_domain_allowed( 'a@notwheatoncollege.edu', array( 'wheatoncollege.edu' ) ), 'look-alike suffix without a dot is refused' );
ok( ! rar_email_domain_allowed( 'a@wheatoncollege.edu.evil.com', array( 'wheatoncollege.edu' ) ), 'domain as a prefix of another is refused' );
ok( ! rar_email_domain_allowed( 'no-at-sign', array( 'wheatoncollege.edu' ) ), 'no @ is refused' );
ok( ! rar_email_domain_allowed( '', array( 'wheatoncollege.edu' ) ), 'empty email is refused' );
ok( rar_email_domain_allowed( 'a@b.edu', array( 'x.com', 'b.edu' ) ), 'any listed domain matches' );
ok( rar_email_domain_allowed( 'a@wheatoncollege.edu@evil.com', array( 'evil.com' ) ), 'the last @ decides the domain' );
eq( '@a.edu', rar_domains_phrase( array( 'a.edu' ) ), 'domains phrase: one' );
eq( '@a.edu or @b.edu', rar_domains_phrase( array( 'a.edu', 'b.edu' ) ), 'domains phrase: two' );
eq( '@a.edu, @b.edu, or @c.edu', rar_domains_phrase( array( 'a.edu', 'b.edu', 'c.edu' ) ), 'domains phrase: three' );

/* ---------------- time helpers ---------------- */

eq( 570, rar_hhmm2min( '09:30' ), 'hhmm2min' );
eq( 1440, rar_hhmm2min( '24:00' ), 'hhmm2min 24:00' );
eq( 540, rar_hhmm2min( '9' ), 'hhmm2min without minutes' );
eq( 0, rar_hhmm2min( '' ), 'hhmm2min empty' );
eq( '09:30', rar_min2hhmm( 570 ), 'min2hhmm' );
eq( '00:00', rar_min2hhmm( 0 ), 'min2hhmm zero' );
eq( '24:00', rar_min2hhmm( 1440 ), 'min2hhmm end of day' );
eq( '10:30', rar_min2hhmm( '630' ), 'min2hhmm numeric string' );

fresh();
eq( 'America/New_York', rar_tz(), 'timezone falls back to New York with no hours option' );
$GLOBALS['rar_options']['mcw_library_hours'] = array( 'timezone' => 'Europe/Paris' );
eq( 'Europe/Paris', rar_tz(), 'timezone read from the hours option' );
$GLOBALS['rar_options']['mcw_library_hours'] = array( 'timezone' => 'Not/AZone' );
eq( 'America/New_York', rar_tz(), 'invalid timezone falls back' );
$GLOBALS['rar_options']['mcw_library_hours'] = 'junk';
eq( null, rar_hours_option(), 'non-array hours option is treated as missing' );
eq( 'America/New_York', rar_tz(), 'timezone fallback when hours option is junk' );
eq( 'Europe/Paris', ( function () { $GLOBALS['rar_options']['mcw_library_hours'] = array( 'timezone' => 'Europe/Paris' ); return rar_now()->getTimezone()->getName(); } )(), 'rar_now uses the library timezone' );

/* ---------------- open blocks and candidate slots ---------------- */

fresh();
eq( array(), rar_open_blocks( '2027-03-01' ), 'no hours plugin: no blocks' );
eq( array(), rar_candidate_slots( '2027-03-01' ), 'no hours plugin: no slots' );
eq( array( array( 'open' => 'x', 'close' => 'y' ) ), rar_normalize_blocks( array( array( 'open' => 'x', 'close' => 'y', 'extra' => 1 ), 'junk', array( 'open' => '09:00' ), array(), null ) ), 'normalize blocks: malformed dropped, extra keys dropped' );
eq( array(), rar_normalize_blocks( 'junk' ), 'normalize blocks: non-array' );

// 2027-03-01 is a Monday (dow 1); 2027-03-07 is a Sunday (dow 0).
$GLOBALS['rar_options']['mcw_library_hours'] = array(
	'timezone'   => 'America/New_York',
	'weekly'     => array( '1' => array( array( 'open' => '09:00', 'close' => '17:00' ) ), '0' => array() ),
	'ranges'     => array(
		'junk',
		array( 'start' => '', 'end' => '2027-03-31' ),
		array( 'start' => '2027-03-01', 'end' => '2027-03-05', 'weekly' => array( '1' => array( array( 'open' => '10:00', 'close' => '12:00' ) ) ) ),
		array( 'start' => '2027-03-01', 'end' => '2027-03-31', 'weekly' => array( '1' => array( array( 'open' => '08:00', 'close' => '09:00' ) ) ) ),
	),
	'exceptions' => array(
		'2027-03-08' => array( 'closed' => true, array( 'open' => '09:00', 'close' => '17:00' ) ),
		'2027-03-15' => array( array( 'open' => '13:00', 'close' => '14:00' ) ),
		'2027-03-22' => 'junk',
	),
);
eq( array( array( 'open' => '10:00', 'close' => '12:00' ) ), rar_open_blocks( '2027-03-01' ), 'first matching term range beats weekly default' );
eq( array(), rar_open_blocks( '2027-03-07' ), 'range weekly with no entry for that day: closed' );
eq( array(), rar_open_blocks( '2027-03-08' ), 'exception marked closed: no blocks' );
eq( array( array( 'open' => '13:00', 'close' => '14:00' ) ), rar_open_blocks( '2027-03-15' ), 'exception beats range and weekly' );
eq( array( array( 'open' => '08:00', 'close' => '09:00' ) ), rar_open_blocks( '2027-03-29' ), 'later range used when the first does not cover the date' );
eq( array( array( 'open' => '09:00', 'close' => '17:00' ) ), rar_open_blocks( '2027-04-05' ), 'outside every range: weekly default' );
eq( array(), rar_open_blocks( '2027-04-06' ), 'weekly default with no entry for that day: closed' );
eq( array( array( 'open' => '08:00', 'close' => '09:00' ) ), rar_open_blocks( '2027-03-22' ), 'non-array exception ignored, falls through to range' );

set_hours( array( array( 'open' => '09:00', 'close' => '10:30' ) ) );
eq( array( '09:00', '09:30', '10:00' ), rar_candidate_slots( '2027-03-01' ), 'slots: 09:00-10:30 gives three, last ends exactly at close' );
set_hours( array( array( 'open' => '09:00', 'close' => '10:15' ) ) );
eq( array( '09:00', '09:30' ), rar_candidate_slots( '2027-03-01' ), 'slots: a slot that would run past close is not offered' );
set_hours( array( array( 'open' => '09:00', 'close' => '09:20' ) ) );
eq( array(), rar_candidate_slots( '2027-03-01' ), 'slots: block shorter than 30 minutes gives none' );
set_hours( array( array( 'open' => '09:15', 'close' => '10:45' ) ) );
eq( array( '09:15', '09:45', '10:15' ), rar_candidate_slots( '2027-03-01' ), 'slots: odd opening time keeps its own grid' );
set_hours( array( array( 'open' => '23:00', 'close' => '24:00' ) ) );
eq( array( '23:00', '23:30' ), rar_candidate_slots( '2027-03-01' ), 'slots: close at 24:00 offers 23:30' );
set_hours( array( array( 'open' => '00:00', 'close' => '24:00' ) ) );
eq( 48, count( rar_candidate_slots( '2027-03-01' ) ), 'slots: all day gives 48' );
set_hours( array( array( 'open' => '13:00', 'close' => '14:00' ), array( 'open' => '09:00', 'close' => '10:00' ), array( 'open' => '09:30', 'close' => '10:30' ) ) );
eq( array( '09:00', '09:30', '10:00', '13:00', '13:30' ), rar_candidate_slots( '2027-03-01' ), 'slots: blocks merged, de-duplicated and sorted' );
set_hours( array( array( 'open' => '10:00', 'close' => '09:00' ) ) );
eq( array(), rar_candidate_slots( '2027-03-01' ), 'slots: close before open gives none' );

/* ---------------- small formatters ---------------- */

eq( '1 hour', rar_fmt_minutes( 60 ), 'fmt minutes: 1 hour' );
eq( '2 hours', rar_fmt_minutes( 120 ), 'fmt minutes: 2 hours' );
eq( '90 minutes', rar_fmt_minutes( 90 ), 'fmt minutes: 90' );
eq( '30 minutes', rar_fmt_minutes( 30 ), 'fmt minutes: 30' );
eq( '0 minutes', rar_fmt_minutes( 0 ), 'fmt minutes: 0' );
$m = rar_daily_limit_message( 120, 90 );
ok( false !== strpos( $m, '2 hours per person per day' ) && false !== strpos( $m, '90 minutes booked' ) && false !== strpos( $m, 'still book up to 30 minutes' ), 'daily limit message with time left' );
$m = rar_daily_limit_message( 120, 120 );
ok( false !== strpos( $m, 'choose another date' ) && false === strpos( $m, 'still book' ), 'daily limit message when nothing is left' );
$m = rar_daily_limit_message( 60, 200 );
ok( false !== strpos( $m, 'choose another date' ), 'daily limit message never reports negative time left' );
eq( 50, rar_pct( 1, 2 ), 'pct' );
eq( null, rar_pct( 5, 0 ), 'pct: no denominator gives null' );
eq( 100, rar_pct( 9, 3 ), 'pct capped at 100' );
eq( '09:00', rar_hour_label( 9, false ), 'hour label 24h' );
eq( '12 AM', rar_hour_label( 0, true ), 'hour label midnight 12h' );
eq( '12 PM', rar_hour_label( 12, true ), 'hour label noon 12h' );
eq( '1 PM', rar_hour_label( 13, true ), 'hour label 13 12h' );
eq( '—', rar_fmt_lead( null ), 'lead: null' );
eq( '1 minute', rar_fmt_lead( 1 ), 'lead: 1 minute' );
eq( '45 minutes', rar_fmt_lead( 45 ), 'lead: minutes' );
eq( '1 hour', rar_fmt_lead( 60 ), 'lead: 1 hour' );
eq( '5 hours', rar_fmt_lead( 300 ), 'lead: hours' );
eq( '2 days', rar_fmt_lead( 2880 ), 'lead: days' );
eq( '1 hour', rar_fmt_hours_words( 60 ), 'hours words: singular' );
eq( '2.5 hours', rar_fmt_hours_words( 150 ), 'hours words: fractional' );
eq( '2 hours', rar_fmt_hours_words( 120 ), 'hours words: plural' );
eq( 'Resolved', rar_reason_label( 'Resolved' ), 'reason label: unknown passes through' );
eq( 'Staff', rar_reason_label( 'staff' ), 'reason label: staff' );
eq( 'Email domain not allowed', rar_reason_label( 'domain' ), 'reason label: domain' );
eq( '2027-03-02', rar_shift_date( '2027-03-01', 1 ), 'shift date +1' );
eq( '2027-03-01', rar_shift_date( '2027-03-31', -30 ), 'shift date -30' );
eq( '2028-02-29', rar_shift_date( '2028-02-28', 1 ), 'shift date into a leap day' );
eq( '2027-01-01', rar_shift_date( '2026-12-31', 1 ), 'shift date across new year' );
eq( "'=SUM(A1)", rar_csv_cell( '=SUM(A1)' ), 'csv: formula prefix neutralised (=)' );
eq( "'+1", rar_csv_cell( '+1' ), 'csv: +' );
eq( "'-1", rar_csv_cell( '-1' ), 'csv: -' );
eq( "'@x", rar_csv_cell( '@x' ), 'csv: @' );
eq( 'Room 1', rar_csv_cell( 'Room 1' ), 'csv: plain text untouched' );
eq( 5, rar_csv_cell( 5 ), 'csv: numbers untouched' );
eq( '', rar_csv_cell( '' ), 'csv: empty untouched' );

/* ---------------- stats date range ---------------- */

fresh();
$today = rar_now()->format( 'Y-m-d' );
eq( array( rar_shift_date( $today, -29 ), $today ), rar_stats_range(), 'stats range defaults to the last 30 days' );
$_GET = array( 'from' => '2027-01-10', 'to' => '2027-01-20' );
eq( array( '2027-01-10', '2027-01-20' ), rar_stats_range(), 'stats range from the query string' );
$_GET = array( 'from' => '2027-01-20', 'to' => '2027-01-10' );
eq( array( '2027-01-10', '2027-01-20' ), rar_stats_range(), 'stats range reversed is swapped' );
$_GET = array( 'from' => 'junk', 'to' => '2027-01-31<b>' );
eq( array( '2027-01-02', '2027-01-31' ), rar_stats_range(), 'stats range: bad "from" ignored, tags stripped from "to" before validating' );
$_GET = array( 'from' => '2000-01-01', 'to' => '2027-01-31' );
eq( array( '2025-01-31', '2027-01-31' ), rar_stats_range(), 'stats range capped at two years' );
$_GET = array( 'to' => '2027-01-31' );
eq( array( '2027-01-02', '2027-01-31' ), rar_stats_range(), 'stats range: only "to" gives 30 days ending there' );
$_GET = array();

/* ---------------- REST: GET /config (public) ---------------- */

fresh();
$cfg_get = route( '/config', 'GET' );
eq( '__return_true', $cfg_get['permission_callback'], 'GET /config is public' );
$GLOBALS['rar_options']['rar_rooms'] = array(
	'rules' => array( 'maxMin' => 90 ),
	'rooms' => array( array( 'id' => 'r1', 'name' => 'Quiet', 'capacity' => 4, 'notes' => 'n', 'hidden' => true, 'hideFrom' => '', 'hideUntil' => '', 'booker_email' => 'leak@example.edu' ) ),
);
$out = $cfg_get['callback']();
eq( array( 'rules', 'rooms', 'hoursReady' ), array_keys( $out ), 'GET /config: response keys' );
eq( false, $out['hoursReady'], 'GET /config: hoursReady false with no hours plugin' );
eq( 90, $out['rules']['maxMin'], 'GET /config: stored rule returned' );
ok( false === strpos( json_encode( $out ), 'leak@example.edu' ), 'GET /config: unknown fields on a room are not exposed' );
set_hours( day9to5() );
eq( true, $cfg_get['callback']()['hoursReady'], 'GET /config: hoursReady true when hours exist' );

/* ---------------- REST: POST /config (staff only) ---------------- */

fresh();
$cfg_post = route( '/config', 'POST' );
$GLOBALS['rar_can'] = false;
eq( false, $cfg_post['permission_callback'](), 'POST /config refused without edit_pages' );
$GLOBALS['rar_can'] = true;
eq( true, $cfg_post['permission_callback'](), 'POST /config allowed with edit_pages' );
is_err( $cfg_post['callback']( new WP_REST_Request( array(), null ) ), 'rar_bad', 400, 'POST /config: no body is rejected' );
is_err( $cfg_post['callback']( new WP_REST_Request( array(), 'junk' ) ), 'rar_bad', 400, 'POST /config: non-array body is rejected' );
is_err( $cfg_post['callback']( new WP_REST_Request( array(), array( 'rules' => array() ) ) ), 'rar_bad', 400, 'POST /config: body without rooms is rejected' );
ok( ! array_key_exists( 'rar_rooms', $GLOBALS['rar_options'] ), 'POST /config: rejected request saves nothing' );
$res = $cfg_post['callback']( new WP_REST_Request( array(), array(
	'rules' => array( 'maxMin' => 9999, 'dailyMaxMin' => 10, 'emailDomains' => '@Wheatoncollege.edu', 'evil' => 1 ),
	'rooms' => array( array( 'id' => 'r1', 'name' => '<script>x</script>Room', 'capacity' => '3', 'junk' => 'y' ) ),
	'extra' => 'dropped',
) ) );
eq( true, $res['ok'], 'POST /config: ok' );
eq( 0, $res['inHidden'], 'POST /config: no reservations in hidden rooms' );
eq( 480, $res['rules']['maxMin'], 'POST /config: sanitized rules echoed back' );
$saved = $GLOBALS['rar_options']['rar_rooms'];
eq( array( 'rules', 'rooms' ), array_keys( $saved ), 'POST /config: only rules and rooms stored' );
eq( 'xRoom', $saved['rooms'][0]['name'], 'POST /config: room name sanitized before saving' );
eq( array( 'wheatoncollege.edu' ), $saved['rules']['emailDomains'], 'POST /config: domains normalised before saving' );
eq( 480, $saved['rules']['dailyMaxMin'], 'POST /config: daily limit raised to max length' );
ok( ! isset( $saved['rooms'][0]['junk'] ) && ! isset( $saved['rules']['evil'] ), 'POST /config: unknown keys not stored' );

/* ---------------- rar_bookings_in_hidden_rooms ---------------- */

fresh();
eq( array(), rar_bookings_in_hidden_rooms(), 'no hidden rooms: nothing flagged and no query' );
eq( 0, count( $wpdb->queries ), 'no hidden rooms: database not touched' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => array(), 'rooms' => array(
	array( 'id' => 'r1', 'name' => 'A', 'capacity' => 1, 'notes' => '', 'hidden' => true, 'hideFrom' => '2027-05-10', 'hideUntil' => '2027-05-12' ),
	array( 'id' => 'r2', 'name' => 'B', 'capacity' => 1, 'notes' => '' ),
) );
$wpdb->results_queue[] = array(
	(object) array( 'booking_id' => 'in', 'room_id' => 'r1', 'start_at' => '2027-05-11 10:00:00' ),
	(object) array( 'booking_id' => 'before', 'room_id' => 'r1', 'start_at' => '2027-05-09 10:00:00' ),
	(object) array( 'booking_id' => 'visible_room', 'room_id' => 'r2', 'start_at' => '2027-05-11 10:00:00' ),
	(object) array( 'booking_id' => 'gone_room', 'room_id' => 'zz', 'start_at' => '2027-05-11 10:00:00' ),
);
eq( array( 'in' => true ), rar_bookings_in_hidden_rooms(), 'only bookings in a hidden room on a hidden day are flagged' );

/* ---------------- REST: GET /availability ---------------- */

fresh();
$av = 'rar_availability';
is_err( $av( new WP_REST_Request( array( 'date' => 'junk' ) ) ), 'rar_date', 400, 'availability: junk date' );
is_err( $av( new WP_REST_Request( array( 'date' => '2027-1-1' ) ) ), 'rar_date', 400, 'availability: malformed date' );
is_err( $av( new WP_REST_Request( array() ) ), 'rar_date', 400, 'availability: missing date' );
is_err( $av( new WP_REST_Request( array( 'date' => plus_days( 1 ) ) ) ), 'rar_hours', 503, 'availability: 503 when the hours plugin is missing' );
set_hours( day9to5() );
is_err( $av( new WP_REST_Request( array( 'date' => plus_days( -1 ) ) ) ), 'rar_window', 400, 'availability: yesterday is outside the window' );
is_err( $av( new WP_REST_Request( array( 'date' => plus_days( 15 ) ) ) ), 'rar_window', 400, 'availability: day 15 is outside the 14-day window' );
$a = $av( new WP_REST_Request( array( 'date' => plus_days( 14 ) ) ) );
ok( is_array( $a ) && plus_days( 14 ) === $a['date'], 'availability: last day of the window is allowed' );
$wpdb->results_queue[] = array(
	(object) array( 'room_id' => 'r1', 'hhmm' => '10:00' ),
	(object) array( 'room_id' => 'r1', 'hhmm' => '10:30' ),
	(object) array( 'room_id' => 'r2', 'hhmm' => '09:00' ),
);
$a = $av( new WP_REST_Request( array( 'date' => plus_days( 2 ) ) ) );
eq( array( 'date', 'blocks', 'slots', 'slotMin', 'maxMin', 'dailyMaxMin', 'rooms', 'roomsHidden', 'taken', 'nowMin' ), array_keys( $a ), 'availability: response keys (no patron fields)' );
eq( array( 'r1' => array( '10:00', '10:30' ), 'r2' => array( '09:00' ) ), $a['taken'], 'availability: taken times grouped by room, times only' );
eq( null, $a['nowMin'], 'availability: nowMin is null for a future date' );
eq( 16, count( $a['slots'] ), 'availability: 16 slots for 09:00-17:00' );
eq( array( 30, 120, 0 ), array( $a['slotMin'], $a['maxMin'], $a['dailyMaxMin'] ), 'availability: rules in response' );
eq( 2, count( $a['rooms'] ), 'availability: both default rooms listed' );
eq( 0, $a['roomsHidden'], 'availability: none hidden' );
$a = $av( new WP_REST_Request( array( 'date' => plus_days( 0 ) ) ) );
ok( is_int( $a['nowMin'] ) && $a['nowMin'] >= 0 && $a['nowMin'] < 1440, 'availability: nowMin is minutes since midnight for today' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => array(), 'rooms' => array(
	array( 'id' => 'r1', 'name' => 'A', 'capacity' => 1, 'notes' => '', 'hidden' => true, 'hideFrom' => plus_days( 3 ), 'hideUntil' => plus_days( 4 ) ),
	array( 'id' => 'r2', 'name' => 'B', 'capacity' => 1, 'notes' => '' ),
) );
$a = $av( new WP_REST_Request( array( 'date' => plus_days( 3 ) ) ) );
eq( array( 'r2' ), array_column( $a['rooms'], 'id' ), 'availability: room hidden on that day is left out' );
eq( 1, $a['roomsHidden'], 'availability: roomsHidden counts it' );
$a = $av( new WP_REST_Request( array( 'date' => plus_days( 5 ) ) ) );
eq( 2, count( $a['rooms'] ), 'availability: room is back after the hidden range' );
$a = $av( new WP_REST_Request( array( 'date' => plus_days( 1 ) ) ) );
eq( 2, count( $a['rooms'] ), 'availability: room is listed before the hidden range' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => array( 'advanceDays' => 3 ), 'rooms' => array() );
is_err( $av( new WP_REST_Request( array( 'date' => plus_days( 4 ) ) ) ), 'rar_window', 400, 'availability: window follows the advanceDays rule' );
$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => array(), 'rooms' => array( array( 'id' => 'r1', 'name' => 'Open', 'capacity' => 1, 'notes' => '' ) ) );
$wpdb->reset();
$av( new WP_REST_Request( array( 'date' => "2027-01-01' OR '1'='1" ) ) );
eq( 0, count( $wpdb->queries ), 'availability: injection-style date never reaches the database' );

/* ---------------- rar_booked_minutes_on ---------------- */

fresh();
$wpdb->var_queue = array( '3' );
eq( 90, rar_booked_minutes_on( 'a@b.edu', '2027-03-01' ), 'booked minutes: rows x 30' );
ok( false !== strpos( $wpdb->queries[0], "'2027-03-01 00:00:00'" ) && false !== strpos( $wpdb->queries[0], "'2027-03-02 00:00:00'" ), 'booked minutes: half-open day range' );
ok( false !== strpos( $wpdb->queries[0], 'LOWER(email) = LOWER(' ), 'booked minutes: email compared case-insensitively' );
$wpdb->reset();
rar_booked_minutes_on( 'a@b.edu', '2027-12-31' );
ok( false !== strpos( $wpdb->queries[0], "'2028-01-01 00:00:00'" ), 'booked minutes: range rolls over the year end' );
$wpdb->reset();
rar_booked_minutes_on( "x'@b.edu", '2027-03-01' );
ok( false !== strpos( $wpdb->queries[0], "x\\'@b.edu" ), 'booked minutes: quote in email is escaped by prepare' );

/* ---------------- REST: POST /reserve ---------------- */

function set_rules( $rules, $rooms = null ) {
	$GLOBALS['rar_options']['rar_rooms'] = array( 'rules' => $rules, 'rooms' => null === $rooms ? rar_defaults()['rooms'] : $rooms );
}
eq( '__return_true', route( '/reserve', 'POST' )['permission_callback'], 'POST /reserve is public (nonce and honeypot guard it)' );

fresh();
set_hours( day9to5() );
is_err( rar_reserve( reserve_req( array( '_wpnonce' => 'bad' ) ) ), 'rar_nonce', 403, 'reserve: bad nonce' );
is_err( rar_reserve( reserve_req( array( '_wpnonce' => null ) ) ), 'rar_nonce', 403, 'reserve: missing nonce' );
is_err( rar_reserve( reserve_req( array( 'rar_hp' => 'http://spam' ) ) ), 'rar_bot', 400, 'reserve: filled honeypot' );
ok( 'rar_nonce' === rar_reserve( reserve_req( array( '_wpnonce' => 'bad', 'rar_hp' => 'x' ) ) )->get_error_code(), 'reserve: nonce checked before honeypot' );
is_err( rar_reserve( reserve_req( array( 'first' => '' ) ) ), 'rar_name', 400, 'reserve: missing first name' );
is_err( rar_reserve( reserve_req( array( 'last' => '   ' ) ) ), 'rar_name', 400, 'reserve: blank last name' );
is_err( rar_reserve( reserve_req( array( 'first' => '<b></b>' ) ) ), 'rar_name', 400, 'reserve: name that is only tags' );
is_err( rar_reserve( reserve_req( array( 'first' => null ) ) ), 'rar_name', 400, 'reserve: null name' );
is_err( rar_reserve( reserve_req( array( 'email' => 'nope' ) ) ), 'rar_email', 400, 'reserve: not an email' );
is_err( rar_reserve( reserve_req( array( 'email' => '' ) ) ), 'rar_email', 400, 'reserve: empty email' );
is_err( rar_reserve( reserve_req( array( 'email' => 'a@b' ) ) ), 'rar_email', 400, 'reserve: email with no TLD' );
is_err( rar_reserve( reserve_req( array( 'date' => '2027-1-1' ) ) ), 'rar_input', 400, 'reserve: malformed date' );
is_err( rar_reserve( reserve_req( array( 'start' => '9:00' ) ) ), 'rar_input', 400, 'reserve: malformed start time' );
is_err( rar_reserve( reserve_req( array( 'date' => '' ) ) ), 'rar_input', 400, 'reserve: empty date' );
is_err( rar_reserve( reserve_req( array( 'room_id' => 'nope' ) ) ), 'rar_room', 400, 'reserve: unknown room' );
is_err( rar_reserve( reserve_req( array( 'room_id' => '' ) ) ), 'rar_room', 400, 'reserve: empty room' );
is_err( rar_reserve( reserve_req( array( 'duration' => 0 ) ) ), 'rar_dur', 400, 'reserve: duration 0' );
is_err( rar_reserve( reserve_req( array( 'duration' => 15 ) ) ), 'rar_dur', 400, 'reserve: duration under 30' );
is_err( rar_reserve( reserve_req( array( 'duration' => 45 ) ) ), 'rar_dur', 400, 'reserve: duration not a multiple of 30' );
is_err( rar_reserve( reserve_req( array( 'duration' => 150 ) ) ), 'rar_dur', 400, 'reserve: duration over the 120 maximum' );
is_err( rar_reserve( reserve_req( array( 'duration' => -60 ) ) ), 'rar_dur', 400, 'reserve: negative duration' );
is_err( rar_reserve( reserve_req( array( 'duration' => 'abc' ) ) ), 'rar_dur', 400, 'reserve: junk duration' );
is_err( rar_reserve( reserve_req( array( 'date' => plus_days( -1 ) ) ) ), 'rar_window', 400, 'reserve: yesterday' );
is_err( rar_reserve( reserve_req( array( 'date' => plus_days( 15 ) ) ) ), 'rar_window', 400, 'reserve: beyond the advance window' );
is_err( rar_reserve( reserve_req( array( 'start' => '08:30' ) ) ), 'rar_hours', 400, 'reserve: starts before opening' );
is_err( rar_reserve( reserve_req( array( 'start' => '08:30', 'duration' => 30 ) ) ), 'rar_hours', 400, 'reserve: the half hour before opening' );
is_err( rar_reserve( reserve_req( array( 'start' => '16:30', 'duration' => 60 ) ) ), 'rar_hours', 400, 'reserve: runs past closing' );
is_err( rar_reserve( reserve_req( array( 'start' => '17:00', 'duration' => 30 ) ) ), 'rar_hours', 400, 'reserve: starts exactly at closing' );
is_err( rar_reserve( reserve_req( array( 'start' => '10:15' ) ) ), 'rar_hours', 400, 'reserve: start not on the slot grid' );
is_err( rar_reserve( reserve_req( array( 'start' => '25:00' ) ) ), 'rar_hours', 400, 'reserve: impossible start time' );
eq( 0, count( $wpdb->queries ), 'reserve: every rejection above happened before any database write' );
eq( 0, count( $GLOBALS['rar_mail'] ), 'reserve: no email for rejected requests' );

// Hidden room.
set_rules( array(), array(
	array( 'id' => 'r1', 'name' => 'A', 'capacity' => 1, 'notes' => '', 'hidden' => true, 'hideFrom' => plus_days( 1 ), 'hideUntil' => plus_days( 1 ) ),
	array( 'id' => 'r2', 'name' => 'B', 'capacity' => 1, 'notes' => '' ),
) );
is_err( rar_reserve( reserve_req() ), 'rar_room_hidden', 400, 'reserve: room hidden on that date' );
$r = rar_reserve( reserve_req( array( 'date' => plus_days( 2 ) ) ) );
ok( is_array( $r ) && $r['ok'], 'reserve: same room is bookable the day after the hidden range' );
$wpdb->reset();
$r = rar_reserve( reserve_req( array( 'room_id' => 'r2' ) ) );
ok( is_array( $r ) && $r['ok'], 'reserve: another room is bookable on the hidden day' );

// Hours missing, gaps, exceptions.
fresh();
is_err( rar_reserve( reserve_req() ), 'rar_hours', 400, 'reserve: no hours plugin means nothing is bookable' );
set_hours( array( array( 'open' => '09:00', 'close' => '12:00' ), array( 'open' => '13:00', 'close' => '17:00' ) ) );
is_err( rar_reserve( reserve_req( array( 'start' => '11:30', 'duration' => 90 ) ) ), 'rar_hours', 400, 'reserve: cannot span the closed gap between blocks' );
$r = rar_reserve( reserve_req( array( 'start' => '11:30', 'duration' => 30 ) ) );
ok( is_array( $r ), 'reserve: last slot before the gap is fine' );
$r = rar_reserve( reserve_req( array( 'start' => '13:00', 'duration' => 30 ) ) );
ok( is_array( $r ), 'reserve: first slot after the gap is fine' );
set_hours( day9to5(), array( 'exceptions' => array( plus_days( 1 ) => array( 'closed' => true ) ) ) );
is_err( rar_reserve( reserve_req() ), 'rar_hours', 400, 'reserve: day closed by an exception' );

// Today and the past.
fresh();
set_hours( array( array( 'open' => '00:00', 'close' => '24:00' ) ) );
$nowMin = (int) rar_now()->format( 'G' ) * 60 + (int) rar_now()->format( 'i' );
if ( $nowMin > 60 && $nowMin < 1380 ) {
	is_err( rar_reserve( reserve_req( array( 'date' => plus_days( 0 ), 'start' => '00:00', 'duration' => 30 ) ) ), 'rar_past', 400, 'reserve: a slot earlier today has passed' );
	is_err( rar_reserve( reserve_req( array( 'date' => plus_days( 0 ), 'start' => rar_min2hhmm( $nowMin - ( $nowMin % 30 ) - 30 ), 'duration' => 60 ) ) ), 'rar_past', 400, 'reserve: booking that starts in the past is refused even if it ends later' );
	$r = rar_reserve( reserve_req( array( 'date' => plus_days( 0 ), 'start' => '23:30', 'duration' => 30 ) ) );
	ok( is_array( $r ), 'reserve: a later slot today is bookable' );
} else {
	echo "SKIP: today's-slot cases (too close to midnight to be deterministic)\n";
}

// Successful booking.
fresh();
set_hours( day9to5() );
$date = plus_days( 1 );
$r = rar_reserve( reserve_req( array( 'date' => $date, 'start' => '10:00', 'duration' => 90, 'first' => '<b>Ada</b>', 'last' => "Love<i>lace</i>", 'email' => ' Ada@WheatonCollege.edu ' ) ) );
eq( array( 'ok' => true, 'token' => str_repeat( 'A', 32 ) ), $r, 'reserve: success returns ok and the cancel token' );
$sql = $wpdb->queries[0];
eq( 3, substr_count( $sql, "'" . $date . " 1" ) , 'reserve: one row per 30-minute slot (3 for 90 minutes)' );
foreach ( array( '10:00:00', '10:30:00', '11:00:00' ) as $t ) { ok( false !== strpos( $sql, "'$date $t'" ), "reserve: slot row $date $t" ); }
ok( false === strpos( $sql, "'$date 11:30:00'" ), 'reserve: no slot row after the end time' );
ok( false !== strpos( $sql, "'Ada'" ) && false !== strpos( $sql, "'Lovelace'" ), 'reserve: names saved without HTML' );
ok( false !== strpos( $sql, "'Ada@WheatonCollege.edu'" ), 'reserve: email saved after sanitizing' );
ok( false !== strpos( $sql, "'r1'" ), 'reserve: room id saved' );
eq( 1, count( $GLOBALS['rar_mail'] ), 'reserve: exactly one confirmation email' );
$mail = $GLOBALS['rar_mail'][0];
eq( 'Ada@WheatonCollege.edu', $mail['to'], 'email: goes to the sanitized booker address' );
eq( 'Room reservation confirmed — Study Room 1 on ' . $date, $mail['subject'], 'email: subject names room and date' );
ok( false !== strpos( $mail['body'], 'Time: 10:00 – 11:30' ), 'email: body has start and end time' );
ok( false !== strpos( $mail['body'], 'Room: Study Room 1' ) && false !== strpos( $mail['body'], 'Date: ' . $date ), 'email: body has room and date' );
ok( false !== strpos( $mail['body'], 'https://example.test/?rar_cancel=' . str_repeat( 'A', 32 ) ), 'email: body has the cancel link with the token' );
eq( array( 'Reply-To: ' . RAR_REPLY_TO ), $mail['headers'], 'email: reply-to header' );
eq( array(), denied_reasons(), 'reserve: a good booking logs nothing as turned away' );

$wpdb->reset(); $GLOBALS['rar_mail'] = array(); $GLOBALS['rar_mail_ok'] = false;
$r = rar_reserve( reserve_req() );
ok( is_array( $r ) && $r['ok'], 'reserve: still succeeds when the email cannot be sent' );
$GLOBALS['rar_mail_ok'] = true;

$wpdb->reset(); $GLOBALS['rar_mail'] = array();
$r = rar_reserve( reserve_req( array( 'room_id' => 'r1;DROP', 'date' => plus_days( 14 ), 'start' => '16:30', 'duration' => 30 ) ) );
ok( is_array( $r ), 'reserve: boundary booking (last day of window, last slot of the day) works' );
ok( false !== strpos( $wpdb->queries[0], "'r1'" ) && false === strpos( $wpdb->queries[0], 'DROP' ), 'reserve: room id is stripped to letters and digits before it reaches SQL' );

// Database errors.
$wpdb->reset(); $GLOBALS['rar_mail'] = array();
$wpdb->query_result = false; $wpdb->last_error = "Duplicate entry 'r1-2027' for key 'room_slot'";
is_err( rar_reserve( reserve_req() ), 'rar_taken', 409, 'reserve: duplicate key means slot taken (409)' );
eq( array( 'taken' ), denied_reasons(), 'reserve: taken request is logged as turned away' );
eq( 0, count( $GLOBALS['rar_mail'] ), 'reserve: no email when the slot was taken' );
$wpdb->reset();
$wpdb->query_result = false; $wpdb->last_error = 'Connection lost';
is_err( rar_reserve( reserve_req() ), 'rar_db', 500, 'reserve: other database error is a 500' );
eq( array(), denied_reasons(), 'reserve: database failure is not logged as turned away' );

// Email domain rule.
fresh();
set_hours( day9to5() );
set_rules( array( 'emailDomains' => array( 'wheatoncollege.edu' ) ) );
is_err( rar_reserve( reserve_req( array( 'email' => 'a@gmail.com' ) ) ), 'rar_email_domain', 400, 'reserve: email outside the allowed domains' );
eq( array( 'domain' ), denied_reasons(), 'reserve: domain refusal is logged' );
ok( false !== strpos( rar_reserve( reserve_req( array( 'email' => 'a@gmail.com' ) ) )->get_error_message(), '@wheatoncollege.edu' ), 'reserve: domain message names the allowed domain' );
$r = rar_reserve( reserve_req( array( 'email' => 'a@lib.wheatoncollege.edu' ) ) );
ok( is_array( $r ), 'reserve: subdomain of an allowed domain can book' );
$wpdb->reset();
is_err( rar_reserve( reserve_req( array( 'email' => 'a@wheatoncollege.edu.evil.com' ) ) ), 'rar_email_domain', 400, 'reserve: look-alike domain refused' );
$wpdb->reset();
rar_reserve( reserve_req( array( 'email' => 'a@gmail.com', 'room_id' => '<x>', 'start' => '9', 'duration' => 99999, 'date' => 'bad' ) ) );
$logged = $wpdb->inserts[0][1];
eq( array( 'kind' => 'denied', 'reason' => 'domain' ), array( 'kind' => $logged['kind'], 'reason' => $logged['reason'] ), 'denied log: junk request still logged' );
eq( array( '', '', null, 480 ), array( $logged['room_id'], $logged['room_name'], $logged['start_at'], $logged['duration_min'] ), 'denied log: junk room dropped, bad date blank, duration clamped to 480' );

// Daily limit.
fresh();
set_hours( day9to5() );
set_rules( array( 'dailyMaxMin' => 120 ) );
$wpdb->var_queue = array( 90 / 30 );              // already 90 minutes booked
$e = rar_reserve( reserve_req( array( 'duration' => 60 ) ) );
is_err( $e, 'rar_daily', 400, 'daily limit: 90 booked + 60 requested exceeds 120' );
ok( false !== strpos( $e->get_error_message(), 'still book up to 30 minutes' ), 'daily limit: message says what is left' );
eq( array( 'daily' ), denied_reasons(), 'daily limit: logged as turned away' );
ok( 0 === count( array_filter( $wpdb->queries, function ( $q ) { return 0 === strpos( $q, 'INSERT INTO' ); } ) ), 'daily limit: pre-check blocks before any insert' );
$wpdb->reset();
$wpdb->var_queue = array( 2, 4 );                  // 60 before, 120 after: exactly at the limit
$r = rar_reserve( reserve_req( array( 'duration' => 60 ) ) );
ok( is_array( $r ), 'daily limit: landing exactly on the limit is allowed' );
eq( 0, count( $wpdb->deletes ), 'daily limit: nothing rolled back when within the limit' );
$wpdb->reset(); $GLOBALS['rar_mail'] = array();
$wpdb->var_queue = array( 2, 6 );                  // another request slipped in between check and insert
$e = rar_reserve( reserve_req( array( 'duration' => 60 ) ) );
is_err( $e, 'rar_daily', 400, 'daily limit: race is caught by the post-insert re-check' );
eq( array( array( 'wp_rar_slots', array( 'booking_id' => str_repeat( 'A', 32 ) ) ) ), $wpdb->deletes, 'daily limit: the new booking is rolled back by booking_id' );
eq( 0, count( $GLOBALS['rar_mail'] ), 'daily limit: no confirmation email after rollback' );
eq( array( 'daily' ), denied_reasons(), 'daily limit: rollback is logged as turned away' );
$wpdb->reset();
$wpdb->var_queue = array( 0, 0 );
set_rules( array( 'dailyMaxMin' => 0 ) );
$r = rar_reserve( reserve_req( array( 'duration' => 120 ) ) );
ok( is_array( $r ) && 0 === count( array_filter( $wpdb->queries, function ( $q ) { return false !== strpos( $q, 'COUNT(*)' ); } ) ), 'daily limit 0: no counting queries at all' );

/* ---------------- cancellation, retiring bookings, anonymous stats ---------------- */

fresh();
$tok = str_repeat( 'a1', 16 );
eq( 0, rar_cancel_booking( '' ), 'cancel: empty token' );
eq( 0, rar_cancel_booking( 'short' ), 'cancel: short token' );
eq( 0, rar_cancel_booking( $tok . 'x' ), 'cancel: 33-character token' );
eq( 0, count( $wpdb->queries ), 'cancel: bad tokens never reach the database' );
eq( 0, rar_cancel_booking( $tok ), 'cancel: valid-looking token with no booking cancels nothing' );
eq( 0, count( $wpdb->inserts ), 'cancel: nothing recorded when nothing was cancelled' );
$wpdb->reset();
eq( 0, rar_cancel_booking( '%27 OR 1=1 --' . str_repeat( 'z', 10 ) ), 'cancel: injection-style token is refused' );
eq( 0, count( $wpdb->queries ), 'cancel: injection-style token never reaches the database' );

$row = (object) array( 'room_id' => 'r1', 'start_at' => '2027-03-01 10:00:00', 'created_at' => '2027-02-28 09:00:00' );
$wpdb->row_queue[] = $row; $wpdb->delete_n = 3;
eq( 3, rar_cancel_booking( '!!' . $tok . '??' ), 'cancel: punctuation stripped from the token, returns rows deleted' );
ok( false !== strpos( $wpdb->queries[0], "'" . $tok . "'" ), 'cancel: cleaned token used in the query' );
eq( 1, count( $wpdb->inserts ), 'cancel: one anonymous record written' );
$rec = $wpdb->inserts[0][1];
eq( 'wp_rar_stats', $wpdb->inserts[0][0], 'cancel: record goes to the stats table' );
eq( array( 'kind', 'reason', 'room_id', 'room_name', 'start_at', 'duration_min', 'created_at', 'cancelled_at' ), array_keys( $rec ), 'cancel: record has only anonymous columns (no name, email or token)' );
eq( array( 'cancelled', 'patron', 'r1', 'Study Room 1', 90 ), array( $rec['kind'], $rec['reason'], $rec['room_id'], $rec['room_name'], $rec['duration_min'] ), 'cancel: record by patron, length from rows deleted' );
ok( null !== $rec['cancelled_at'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rec['cancelled_at'] ), 'cancel: cancelled_at is set to library time' );
$wpdb->reset(); $wpdb->row_queue[] = $row; $wpdb->delete_n = 1;
rar_cancel_booking( $tok, 'staff' );
eq( 'staff', $wpdb->inserts[0][1]['reason'], 'cancel: staff cancellation is recorded as staff' );
$wpdb->reset(); $wpdb->row_queue[] = $row; $wpdb->delete_n = 0;
eq( 0, rar_cancel_booking( $tok ), 'retire: delete that removed nothing (already cancelled) returns 0' );
eq( 0, count( $wpdb->inserts ), 'retire: no double counting when the delete removed nothing' );
$wpdb->reset(); $wpdb->row_queue[] = (object) array( 'room_id' => 'gone', 'start_at' => '2027-03-01 10:00:00', 'created_at' => '2027-02-28 09:00:00' ); $wpdb->delete_n = 2;
rar_retire_booking( $tok, 'booked' );
$rec = $wpdb->inserts[0][1];
eq( array( 'booked', 'Removed room', null ), array( $rec['kind'], $rec['room_name'], $rec['cancelled_at'] ), 'retire: ended booking in a removed room, no cancelled_at' );

fresh();
rar_log_denied( 'taken', 'r2', '2027-03-01', '10:00', 60 );
$rec = $wpdb->inserts[0][1];
eq( array( 'denied', 'taken', 'r2', 'Study Room 2', '2027-03-01 10:00:00', 60 ), array( $rec['kind'], $rec['reason'], $rec['room_id'], $rec['room_name'], $rec['start_at'], $rec['duration_min'] ), 'denied log: normal request' );
eq( array( 'kind', 'reason', 'room_id', 'room_name', 'start_at', 'duration_min', 'created_at' ), array_keys( $rec ), 'denied log: only anonymous columns' );
$wpdb->reset();
rar_log_denied( 'daily', 'phantom', '2027-02-30x', '25:61', -5 );
$rec = $wpdb->inserts[0][1];
eq( array( '', '', null, 0 ), array( $rec['room_id'], $rec['room_name'], $rec['start_at'], $rec['duration_min'] ), 'denied log: phantom room, bad date and negative length are neutralised' );

fresh();
$wpdb->col_queue[] = array( 'bk1', 'bk2' );
$wpdb->row_queue[] = (object) array( 'room_id' => 'r1', 'start_at' => '2020-01-01 10:00:00', 'created_at' => '2019-12-31 10:00:00' );
$wpdb->row_queue[] = (object) array( 'room_id' => 'r2', 'start_at' => '2020-01-01 11:00:00', 'created_at' => '2019-12-31 10:00:00' );
rar_purge_past();
eq( 2, count( $wpdb->inserts ), 'purge: every ended booking is retired to an anonymous record' );
eq( array( 'booked', 'booked' ), array( $wpdb->inserts[0][1]['kind'], $wpdb->inserts[1][1]['kind'] ), 'purge: records are kind booked' );
$wpdb->reset();
rar_purge_past();
eq( 1, count( $wpdb->queries ), 'purge: nothing ended means one lookup and stop' );

/* ---------------- statistics ---------------- */

fresh();
set_hours( day9to5() );
$wpdb->results_queue[] = array(
	array( 'kind' => 'booked', 'reason' => '', 'room_id' => 'r1', 'room_name' => 'Study Room 1', 'start_at' => '2026-03-02 10:00:00', 'duration_min' => 60, 'created_at' => '2026-03-01 10:00:00', 'cancelled_at' => null ),
	array( 'kind' => 'booked', 'reason' => '', 'room_id' => 'r2', 'room_name' => 'Study Room 2', 'start_at' => '2026-03-02 10:00:00', 'duration_min' => 60, 'created_at' => '2026-03-02 09:00:00', 'cancelled_at' => null ),
	array( 'kind' => 'cancelled', 'reason' => 'patron', 'room_id' => 'r1', 'room_name' => 'Study Room 1', 'start_at' => '2026-03-02 14:00:00', 'duration_min' => 30, 'created_at' => '2026-03-01 10:00:00', 'cancelled_at' => '2026-03-02 13:30:00' ),
	array( 'kind' => 'denied', 'reason' => 'taken', 'room_id' => 'r2', 'room_name' => 'Study Room 2', 'start_at' => '2026-03-02 10:00:00', 'duration_min' => 60, 'created_at' => '2026-03-02 09:30:00', 'cancelled_at' => null ),
	array( 'kind' => 'denied', 'reason' => 'domain', 'room_id' => '', 'room_name' => '', 'start_at' => null, 'duration_min' => 30, 'created_at' => '2026-03-02 09:31:00', 'cancelled_at' => null ),
	array( 'kind' => 'booked', 'reason' => '', 'room_id' => 'old', 'room_name' => 'Old Room', 'start_at' => '2026-03-02 15:00:00', 'duration_min' => 30, 'created_at' => '2026-03-02 15:00:00', 'cancelled_at' => null ),
);
$st = rar_stats( '2026-03-02', '2026-03-02' );
eq( 3, $st['booked'], 'stats: reservations kept' );
eq( 150, $st['minutes'], 'stats: minutes booked' );
eq( 1, $st['cancelled'], 'stats: cancelled' );
eq( 2, $st['denied'], 'stats: turned away' );
eq( array( 'taken' => 1, 'daily' => 0, 'domain' => 1 ), $st['deniedBy'], 'stats: turned away by reason' );
eq( array( 'patron' => 1, 'staff' => 0 ), $st['cancelBy'], 'stats: cancelled by' );
eq( 1, $st['notice']['Less than 1 hour before'], 'stats: cancellation 30 minutes before start is under 1 hour notice' );
eq( 2, $st['roomCount'], 'stats: room count is the current rooms' );
eq( 16, $st['openSlots'], 'stats: open half-hours from Library Hours (09:00-17:00 = 16)' );
eq( 5, $st['occSlots'], 'stats: occupied half-hours' );
eq( 16, $st['util'], 'stats: util is occupied / (open x rooms)' );
eq( 2, $st['fullHalfHours'], 'stats: every current room booked for two half-hours' );
eq( 25, $st['cancelRate'], 'stats: cancellation rate (1 of 4)' );
eq( 1, $st['under1h'], 'stats: one booking made less than an hour ahead (the 15:00 one)' );
eq( array( 'Less than 1 hour' => 1, '1 to 24 hours' => 1, '1 to 3 days' => 1, '3 to 7 days' => 0, 'More than 7 days' => 0 ), $st['lead'], 'stats: lead-time buckets (0, 60 and 1440 minutes)' );
eq( 60, $st['leadMedian'], 'stats: median lead time' );
eq( 500, $st['leadAvg'], 'stats: average lead time' );
eq( 50, $st['avgLen'], 'stats: average length' );
eq( array( 30 => 1, 60 => 2 ), $st['length'], 'stats: lengths sorted' );
eq( array( '2026-03-02' ), array_keys( $st['weeks'] ), 'stats: one Monday-start week' );
eq( array( 'bookings' => 3, 'minutes' => 150 ), $st['weeks']['2026-03-02'], 'stats: week totals' );
eq( 'Old Room', $st['rooms']['old']['name'], 'stats: a removed room still appears with its saved name' );
eq( 2, $st['rooms']['r1']['bookings'] + $st['rooms']['r2']['bookings'], 'stats: current rooms count their bookings' );
eq( 1, $st['rooms']['r2']['denied'], 'stats: turned away counted against the room' );
ok( ! isset( $st['leads'] ), 'stats: raw lead list is not exposed' );
ok( false === strpos( json_encode( $st ), '@' ), 'stats: no email-like data in the result' );
eq( 5, array_sum( array_column( $st['heat'][1], 'occ' ) ), 'stats: heatmap Monday cells hold the 5 occupied half-hours' );
eq( 'Study Room 1', rar_room_name( 'r1' ), 'room name lookup' );
eq( 'Removed room', rar_room_name( 'zz' ), 'room name lookup: unknown id' );

fresh();
$wpdb->results_queue[] = array();
$st = rar_stats( '2026-03-02', '2026-03-08' );
eq( array( 0, 0, 0, 0 ), array( $st['booked'], $st['minutes'], $st['cancelled'], $st['denied'] ), 'stats: empty range is all zero' );
eq( array( null, null, null, null, null ), array( $st['leadAvg'], $st['leadMedian'], $st['avgLen'], $st['cancelRate'], $st['util'] ), 'stats: empty range has no averages or percentages (not divide-by-zero)' );
eq( false, $st['hoursReady'], 'stats: hoursReady false without the hours plugin' );
eq( 0, $st['openSlots'], 'stats: no open time without hours' );
eq( array( '2026-03-02' ), array_keys( $st['weeks'] ), 'stats: a Monday-to-Sunday range is one week' );
$wpdb->reset(); $wpdb->results_queue[] = array();
eq( 2, count( rar_stats( '2026-03-04', '2026-03-09' )['weeks'] ), 'stats: a range that crosses a Monday spans two weeks' );
ok( false !== strpos( $wpdb->queries[0], "'2026-03-04 00:00:00'" ) && false !== strpos( $wpdb->queries[0], "'2026-03-10 00:00:00'" ), 'stats: query range is half-open to the day after "to"' );

/* ---------------- admin: menus, reservations list, CSV permission ---------------- */

fresh();
foreach ( $GLOBALS['rar_hooks']['admin_menu'] as $cb ) { $cb(); }
eq( array( 'edit_pages', 'edit_pages', 'edit_pages' ), array_column( $GLOBALS['rar_menus'], 1 ), 'admin menus all require edit_pages' );
eq( array( 'reserve-a-room', 'reserve-a-room-reservations', 'reserve-a-room-stats' ), array_column( $GLOBALS['rar_menus'], 2 ), 'admin menu slugs' );

$csv = $GLOBALS['rar_hooks']['admin_post_rar_stats_csv'][0];
$GLOBALS['rar_can'] = false;
$died = false;
try { $csv(); } catch ( RAR_Wp_Die $e ) { $died = 403 === $e->getCode(); }
ok( $died, 'CSV export: refused (403) without edit_pages' );
eq( array(), $GLOBALS['rar_referer'], 'CSV export: permission is checked before anything else' );

fresh();
$GLOBALS['rar_can'] = true;
$evil = (object) array( 'booking_id' => 'tok"><b>', 'room_id' => 'r1', 'first' => '<script>alert(1)</script>', 'last' => 'O\'Hara', 'email' => 'x"><img src=x@e.edu', 'start_at' => '2027-03-01 10:00:00', 'last_slot' => '2027-03-01 10:30:00', 'slots' => 2 );
$wpdb->results_queue[] = array( $evil );
ob_start(); rar_reservations_page(); $html = ob_get_clean();
ok( false === strpos( $html, '<script>' ) && false === strpos( $html, '<img' ), 'reservations list: patron fields are HTML-escaped' );
ok( false !== strpos( $html, '10:00 – 11:00' ), 'reservations list: end time is last slot + 30 minutes' );
ok( false !== strpos( $html, 'Study Room 1' ) && false !== strpos( $html, 'name="rar_cancel_token"' ), 'reservations list: room name and a cancel form' );
ok( false === strpos( $html, 'value="tok"><b>' ), 'reservations list: token is escaped inside the form' );
fresh();
ob_start(); rar_reservations_page(); $html = ob_get_clean();
ok( false !== strpos( $html, 'No upcoming reservations' ), 'reservations list: empty state' );

$_POST = array( 'rar_cancel_token' => $tok, '_wpnonce' => 'bad' );
ob_start(); rar_reservations_page(); ob_end_clean();
eq( 0, count( $wpdb->deletes ) + count( array_filter( $wpdb->queries, function ( $q ) { return 0 === strpos( $q, 'DELETE' ); } ) ), 'staff cancel: bad nonce cancels nothing' );
$wpdb->reset(); $GLOBALS['rar_can'] = false; $_POST = array( 'rar_cancel_token' => $tok, '_wpnonce' => 'good' );
ob_start(); rar_reservations_page(); ob_end_clean();
eq( 0, count( array_filter( $wpdb->queries, function ( $q ) { return 0 === strpos( $q, 'DELETE' ); } ) ), 'staff cancel: valid nonce but no capability cancels nothing' );
$wpdb->reset(); $GLOBALS['rar_can'] = true;
$wpdb->row_queue[] = (object) array( 'room_id' => 'r1', 'start_at' => '2027-03-01 10:00:00', 'created_at' => '2027-02-28 09:00:00' );
ob_start(); rar_reservations_page(); $html = ob_get_clean();
ok( false !== strpos( $html, 'Reservation cancelled.' ) && 'staff' === $wpdb->inserts[0][1]['reason'], 'staff cancel: valid nonce and capability cancels, recorded as staff' );
$_POST = array();

/* ---------------- shortcode ---------------- */

fresh();
set_rules( array( 'timeFormat' => '12', 'emailDomains' => array( 'a.edu', 'b.edu' ) ) );
$out = rar_shortcode( array() );
ok( is_string( $out ) && false !== strpos( $out, 'id="rar-rooms"' ), 'shortcode returns the widget markup as a string' );
ok( 1 === preg_match( '/window\.RAR_CFG=(\{.*?\});<\/script>/', $out, $m ), 'shortcode passes config as JSON in window.RAR_CFG' );
$cfg = json_decode( $m[1], true );
eq( array( 'availUrl', 'configUrl', 'reserveUrl', 'nonce', 'fmt12', 'domains' ), array_keys( $cfg ), 'shortcode config keys' );
eq( 'https://example.test/wp-json/reserve-a-room/v1/reserve', $cfg['reserveUrl'], 'shortcode config: reserve URL' );
eq( array( true, array( 'a.edu', 'b.edu' ), 'good' ), array( $cfg['fmt12'], $cfg['domains'], $cfg['nonce'] ), 'shortcode config: 12-hour default, domains, nonce' );
ok( false === strpos( $m[1], '@' ), 'shortcode config carries no email addresses' );
set_rules( array() );
preg_match( '/window\.RAR_CFG=(\{.*?\});<\/script>/', rar_shortcode( array() ), $m );
eq( array( false, array() ), array( json_decode( $m[1], true )['fmt12'], json_decode( $m[1], true )['domains'] ), 'shortcode config: defaults to 24-hour and no domains' );

/* ---------------- legacy migration ---------------- */

fresh();
$GLOBALS['rar_options']['mcw_rooms'] = array( 'rooms' => array( array( 'id' => 'old', 'name' => 'Old' ) ) );
$GLOBALS['rar_options']['mcw_rooms_db_version'] = '1';
rar_migrate_legacy();
eq( array( 'rooms' => array( array( 'id' => 'old', 'name' => 'Old' ) ) ), $GLOBALS['rar_options']['rar_rooms'], 'migrate: pre-2.0 rooms option copied to rar_rooms' );
ok( ! isset( $GLOBALS['rar_options']['mcw_rooms'] ) && ! isset( $GLOBALS['rar_options']['mcw_rooms_db_version'] ), 'migrate: old options removed' );
$GLOBALS['rar_options']['mcw_rooms'] = array( 'rooms' => array() );
rar_migrate_legacy();
eq( array( 'rooms' => array( array( 'id' => 'old', 'name' => 'Old' ) ) ), $GLOBALS['rar_options']['rar_rooms'], 'migrate: never overwrites rooms already saved under the new name' );
$before = $GLOBALS['rar_options'];
rar_migrate_legacy();
eq( $before, $GLOBALS['rar_options'], 'migrate: safe to run repeatedly' );

/* ---------------- summary ---------------- */

echo "\n" . ( $passes + $fails ) . " cases, $passes passed, $fails failed\n";
exit( $fails ? 1 : 0 );
