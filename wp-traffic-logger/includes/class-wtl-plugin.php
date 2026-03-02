<?php
/**
 * Core plugin wiring.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTL_Plugin {
	const OPTION_KEY        = 'wtl_options';
	const UNINSTALL_OPT_KEY = 'wtl_delete_data_on_uninstall';
	const CRON_HOOK         = 'wtl_cleanup_logs_event';

	/**
	 * Request start timestamp.
	 *
	 * @var float
	 */
	private static $request_started_at = 0.0;

	/**
	 * Capture component.
	 *
	 * @var WTL_Request_Capture
	 */
	private static $capture;

	/**
	 * Writer component.
	 *
	 * @var WTL_Log_Writer
	 */
	private static $writer;

	/**
	 * Reader component.
	 *
	 * @var WTL_Log_Reader
	 */
	private static $reader;

	/**
	 * Retention component.
	 *
	 * @var WTL_Retention
	 */
	private static $retention;

	/**
	 * Admin page component.
	 *
	 * @var WTL_Admin_Page
	 */
	private static $admin_page;

	/**
	 * Bootstrap plugin services and hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		self::$request_started_at = microtime( true );
		self::$writer             = new WTL_Log_Writer();
		self::$capture            = new WTL_Request_Capture();
		self::$retention          = new WTL_Retention( self::$writer );

		add_action( self::CRON_HOOK, array( self::$retention, 'cleanup' ) );
		add_action( 'init', array( self::$retention, 'schedule_daily_cleanup' ) );
		add_action( 'shutdown', array( __CLASS__, 'handle_shutdown' ), 9999 );

		if ( is_admin() ) {
			self::$reader     = new WTL_Log_Reader( self::$writer );
			self::$admin_page = new WTL_Admin_Page( self::$reader );

			add_action( 'admin_menu', array( self::$admin_page, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( self::$admin_page, 'enqueue_assets' ) );
		}
	}

	/**
	 * Activation hook callback.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::default_options(), '', false );
		}

		if ( false === get_option( self::UNINSTALL_OPT_KEY, false ) ) {
			add_option( self::UNINSTALL_OPT_KEY, 0, '', false );
		}

		$writer = new WTL_Log_Writer();
		$writer->ensure_log_directory();

		$retention = new WTL_Retention( $writer );
		$retention->schedule_daily_cleanup();
	}

	/**
	 * Deactivation hook callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$retention = new WTL_Retention( new WTL_Log_Writer() );
		$retention->unschedule_cleanup();
	}

	/**
	 * Request shutdown handler.
	 *
	 * @return void
	 */
	public static function handle_shutdown() {
		if ( ! self::$capture || ! self::$writer ) {
			return;
		}

		$options = self::get_options();
		$context = self::$capture->get_request_context();
		$should  = self::$capture->should_log( $context, $options );
		$should  = apply_filters( 'wtl_should_log_request', $should, $context );

		if ( ! $should ) {
			return;
		}

		$entry = self::$capture->build_log_entry( $context, self::$request_started_at, $options );
		$entry = apply_filters( 'wtl_log_entry', $entry, $context );

		if ( ! is_array( $entry ) ) {
			return;
		}

		$write_result = self::$writer->write_entry( $entry );
		do_action( 'wtl_after_log_write', $entry, $write_result );
	}

	/**
	 * Return default plugin options.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'retention_days'  => 14,
			'max_file_size'   => 5 * MB_IN_BYTES,
			'max_body_bytes'  => 64 * KB_IN_BYTES,
			'capture_public'  => 1,
			'capture_rest'    => 1,
			'capture_ajax'    => 1,
			'tail_read_bytes' => 2 * MB_IN_BYTES,
		);
	}

	/**
	 * Return current options with defaults and safe bounds.
	 *
	 * @return array
	 */
	public static function get_options() {
		$defaults = self::default_options();
		$stored   = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$options = wp_parse_args( $stored, $defaults );

		$options['retention_days'] = max( 1, min( 365, absint( $options['retention_days'] ) ) );
		$options['max_file_size']  = max( 64 * KB_IN_BYTES, min( 100 * MB_IN_BYTES, absint( $options['max_file_size'] ) ) );
		$options['max_body_bytes'] = max( 1024, min( 10 * MB_IN_BYTES, absint( $options['max_body_bytes'] ) ) );
		$options['tail_read_bytes'] = max( 64 * KB_IN_BYTES, min( 8 * MB_IN_BYTES, absint( $options['tail_read_bytes'] ) ) );

		$options['capture_public'] = ! empty( $options['capture_public'] ) ? 1 : 0;
		$options['capture_rest']   = ! empty( $options['capture_rest'] ) ? 1 : 0;
		$options['capture_ajax']   = ! empty( $options['capture_ajax'] ) ? 1 : 0;

		return $options;
	}
}
