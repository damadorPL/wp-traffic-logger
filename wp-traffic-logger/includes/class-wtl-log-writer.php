<?php
/**
 * File log writing and rotation.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTL_Log_Writer {
	/**
	 * Log directory slug.
	 *
	 * @var string
	 */
	const LOG_DIR_NAME = 'wp-traffic-logger';

	/**
	 * Ensure log directory and guard files exist.
	 *
	 * @return bool
	 */
	public function ensure_log_directory() {
		$directory = $this->get_log_directory();
		if ( ! $directory ) {
			return false;
		}

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return false;
		}

		$index_path = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index_path ) ) {
			@file_put_contents( $index_path, "<?php\n// Silence is golden.\n" );
		}

		$htaccess_path = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			@file_put_contents( $htaccess_path, "Deny from all\n" );
		}

		$web_config_path = trailingslashit( $directory ) . 'web.config';
		if ( ! file_exists( $web_config_path ) ) {
			$web_config = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n";
			@file_put_contents( $web_config_path, $web_config );
		}

		return true;
	}

	/**
	 * Write a single JSONL entry.
	 *
	 * @param array $entry Log entry.
	 * @return array
	 */
	public function write_entry( $entry ) {
		if ( ! $this->ensure_log_directory() ) {
			return array(
				'success' => false,
				'path'    => '',
				'error'   => 'log_directory_unavailable',
			);
		}

		$encoded_entry = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded_entry ) {
			return array(
				'success' => false,
				'path'    => '',
				'error'   => 'json_encode_failed',
			);
		}

		$target_file = $this->resolve_target_file();
		$handle      = @fopen( $target_file, 'ab' );

		if ( ! $handle ) {
			return array(
				'success' => false,
				'path'    => $target_file,
				'error'   => 'open_failed',
			);
		}

		$write_ok = false;

		if ( flock( $handle, LOCK_EX ) ) {
			$written = fwrite( $handle, $encoded_entry . PHP_EOL );
			fflush( $handle );
			flock( $handle, LOCK_UN );
			$write_ok = false !== $written;
		}

		fclose( $handle );

		return array(
			'success' => $write_ok,
			'path'    => $target_file,
			'error'   => $write_ok ? '' : 'write_failed',
		);
	}

	/**
	 * Return available log files.
	 *
	 * @param string $date_filter Optional date YYYY-MM-DD.
	 * @return array
	 */
	public function list_log_files( $date_filter = '' ) {
		$directory = $this->get_log_directory();
		if ( ! $directory || ! is_dir( $directory ) ) {
			return array();
		}

		$all_files = glob( trailingslashit( $directory ) . '*.log' );
		if ( ! is_array( $all_files ) ) {
			return array();
		}

		$result = array();
		foreach ( $all_files as $file ) {
			$basename = basename( $file );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:-\d{3})?\.log$/', $basename ) ) {
				continue;
			}

			if ( $date_filter && 0 !== strpos( $basename, $date_filter ) ) {
				continue;
			}

			$result[] = $file;
		}

		usort(
			$result,
			function ( $left, $right ) {
				return strcmp( basename( $right ), basename( $left ) );
			}
		);

		return $result;
	}

	/**
	 * Return absolute log directory path.
	 *
	 * @return string
	 */
	public function get_log_directory() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $upload['basedir'] ) . self::LOG_DIR_NAME;
	}

	/**
	 * Resolve active log file considering rotation limits.
	 *
	 * @return string
	 */
	private function resolve_target_file() {
		$options     = WTL_Plugin::get_options();
		$max_size    = isset( $options['max_file_size'] ) ? (int) $options['max_file_size'] : ( 5 * MB_IN_BYTES );
		$directory   = $this->get_log_directory();
		$date_prefix = gmdate( 'Y-m-d' );
		$base_file   = trailingslashit( $directory ) . $date_prefix . '.log';

		if ( ! file_exists( $base_file ) ) {
			return $base_file;
		}

		clearstatcache( true, $base_file );
		if ( filesize( $base_file ) < $max_size ) {
			return $base_file;
		}

		$counter = 1;
		while ( $counter < 1000 ) {
			$file_name = sprintf( '%s-%03d.log', $date_prefix, $counter );
			$path      = trailingslashit( $directory ) . $file_name;

			if ( ! file_exists( $path ) ) {
				return $path;
			}

			clearstatcache( true, $path );
			if ( filesize( $path ) < $max_size ) {
				return $path;
			}

			++$counter;
		}

		return trailingslashit( $directory ) . sprintf( '%s-%03d.log', $date_prefix, 999 );
	}
}
