<?php
/**
 * Stub harness: mocks the WordPress functions and $wpdb the plugin uses, then runs tests/cases.php.
 * Run: php tests/run.php
 * No network, no database, no WordPress.
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );

ini_set( 'error_log', sys_get_temp_dir() . '/rar-tests-error.log' ); // the plugin logs a failed email; keep that out of the test output
error_reporting( E_ALL );

$GLOBALS['rar_options']  = array();
$GLOBALS['rar_hooks']    = array();   // tag => [callbacks]
$GLOBALS['rar_routes']   = array();   // [ns, route, args]
$GLOBALS['rar_menus']    = array();   // [kind, capability, slug]
$GLOBALS['rar_mail']     = array();
$GLOBALS['rar_mail_ok']  = true;
$GLOBALS['rar_can']      = true;      // current_user_can() result
$GLOBALS['rar_referer']  = array();   // check_admin_referer() calls

/* ---- WordPress stubs ---- */

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
	public $params; public $json;
	public function __construct( $params = array(), $json = null ) { $this->params = $params; $this->json = $json; }
	public function get_param( $k ) { return array_key_exists( $k, $this->params ) ? $this->params[ $k ] : null; }
	public function get_json_params() { return $this->json; }
}
class RAR_Wp_Die extends Exception {}

function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $tag, $cb = null ) { $GLOBALS['rar_hooks'][ $tag ][] = $cb; }
function add_filter() {}
function add_shortcode( $tag, $cb ) { $GLOBALS['rar_shortcodes'][ $tag ] = $cb; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['rar_routes'][] = array( $ns, $route, $args ); }
function add_menu_page( $title, $menu, $cap, $slug ) { $GLOBALS['rar_menus'][] = array( 'page', $cap, $slug ); }
function add_submenu_page( $parent, $title, $menu, $cap, $slug ) { $GLOBALS['rar_menus'][] = array( 'sub', $cap, $slug ); }
function get_file_data( $file, $headers ) {
	$src = file_get_contents( $file );
	$out = array();
	foreach ( $headers as $k => $label ) {
		$out[ $k ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $src, $m ) ? trim( $m[1] ) : '';
	}
	return $out;
}
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['rar_options'] ) ? $GLOBALS['rar_options'][ $k ] : $default; }
function update_option( $k, $v ) { $GLOBALS['rar_options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( ! array_key_exists( $k, $GLOBALS['rar_options'] ) ) { $GLOBALS['rar_options'][ $k ] = $v; } return true; }
function delete_option( $k ) { unset( $GLOBALS['rar_options'][ $k ] ); return true; }
function sanitize_text_field( $s ) { if ( is_object( $s ) || is_array( $s ) ) { return ''; } return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_email( $s ) { return preg_replace( '/[^a-z0-9@._+\-]/i', '', (string) $s ); }
function is_email( $s ) { return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $s ); }
function wp_unslash( $s ) { return $s; }
function wp_rand() { return 4; }
function wp_generate_password() { return str_repeat( 'A', 32 ); }
function wp_create_nonce() { return 'good'; }
function wp_verify_nonce( $n ) { return 'good' === $n; }
function wp_nonce_field( $action, $name, $ref, $echo ) { return '<input type="hidden" name="' . $name . '" value="nonce">'; }
function check_admin_referer( $a ) { $GLOBALS['rar_referer'][] = $a; return true; }
function current_user_can() { return $GLOBALS['rar_can']; }
function wp_die( $m = '', $code = 0 ) { throw new RAR_Wp_Die( $m, (int) $code ); }
function rest_ensure_response( $d ) { return $d; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
function esc_url_raw( $s ) { return preg_match( '#^https?://#i', $s ) ? $s : ''; }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function wp_mail( $to, $subject, $body, $headers = array() ) {
	$GLOBALS['rar_mail'][] = compact( 'to', 'subject', 'body', 'headers' );
	return $GLOBALS['rar_mail_ok'];
}

/* ---- Fake $wpdb ---- */

class RAR_Fake_Wpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $queries = array();   // every SQL string passed to query()/get_*()
	public $inserts = array();   // [table, data]
	public $deletes = array();   // [table, where]
	public $var_queue = array(); // get_var() results, shifted in order (default 0 for COUNT, null otherwise)
	public $results_queue = array();
	public $col_queue = array();
	public $row_queue = array();
	public $query_result = null; // null = number of tuples inserted / 1; false = failure
	public $delete_n = 1;

	public function reset() {
		$this->last_error = ''; $this->queries = array(); $this->inserts = array(); $this->deletes = array();
		$this->var_queue = array(); $this->results_queue = array(); $this->col_queue = array(); $this->row_queue = array();
		$this->query_result = null; $this->delete_n = 1;
	}
	public function get_charset_collate() { return ''; }
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, $args = null ) {
		$args = func_get_args();
		array_shift( $args );
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$sql = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql );
		$args = array_map( function ( $a ) { return is_string( $a ) ? addslashes( $a ) : $a; }, $args );
		return vsprintf( $sql, $args );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'SHOW TABLES' ) ) { return null; }
		return $this->var_queue ? array_shift( $this->var_queue ) : 0;
	}
	public function get_results( $sql ) { $this->queries[] = $sql; return $this->results_queue ? array_shift( $this->results_queue ) : array(); }
	public function get_col( $sql ) { $this->queries[] = $sql; return $this->col_queue ? array_shift( $this->col_queue ) : array(); }
	public function get_row( $sql ) { $this->queries[] = $sql; return $this->row_queue ? array_shift( $this->row_queue ) : null; }
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( 0 === strpos( ltrim( $sql ), 'DELETE' ) ) { return $this->delete_n; }
		if ( null !== $this->query_result ) { return $this->query_result; }
		return 1;
	}
	public function insert( $table, $data ) { $this->inserts[] = array( $table, $data ); return 1; }
	public function delete( $table, $where ) { $this->deletes[] = array( $table, $where ); return 1; }
	public function update() { return 1; }
}
$GLOBALS['wpdb'] = new RAR_Fake_Wpdb();

require dirname( __DIR__ ) . '/reserve-a-room.php';

require __DIR__ . '/cases.php';
