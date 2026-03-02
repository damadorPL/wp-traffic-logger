<?php
/**
 * Log retention and cron scheduling.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTL_Retention {
	/**
	 * Log writer.
	 *
	 * @var WTL_Log_Writer
	 */
	private $writer;

	/**
	 * Constructor.
	 *
	 * @param WTL_Log_Writer $writer Log writer.
	 */
	public function __construct( $writer ) {
		$this->writer = $writer;
	}

	/**
	 * Schedule daily cleanup event.
	 *
	 * @return void
	 */
	public function schedule_daily_cleanup() {
		$scheduled = wp_next_scheduled( WTL_Plugin::CRON_HOOK );
		if ( $scheduled ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', WTL_Plugin::CRON_HOOK );
	}

	/**
	 * Unschedule cleanup events.
	 *
	 * @return void
	 */
	public function unschedule_cleanup() {
		$timestamp = wp_next_scheduled( WTL_Plugin::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, WTL_Plugin::CRON_HOOK );
			$timestamp = wp_next_scheduled( WTL_Plugin::CRON_HOOK );
		}
	}

	/**
	 * Remove old log files based on retention policy.
	 *
	 * @return void
	 */
	public function cleanup() {
		$options        = WTL_Plugin::get_options();
		$retention_days = isset( $options['retention_days'] ) ? (int) $options['retention_days'] : 14;
		$cutoff_time    = time() - ( $retention_days * DAY_IN_SECONDS );
		$directory      = $this->writer->get_log_directory();

		if ( ! $directory || ! is_dir( $directory ) ) {
			return;
		}

		$log_files = glob( trailingslashit( $directory ) . '*.log' );
		if ( ! is_array( $log_files ) ) {
			return;
		}

		foreach ( $log_files as $file ) {
			$basename = basename( $file );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:-\d{3})?\.log$/', $basename ) ) {
				continue;
			}

			$file_time = @filemtime( $file );
			if ( false === $file_time ) {
				continue;
			}

			if ( $file_time < $cutoff_time ) {
				@unlink( $file );
			}
		}
	}
}
