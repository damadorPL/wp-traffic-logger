<?php
/**
 * Bounded log file reader for admin pages.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTL_Log_Reader {
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
	 * Read entries with filters and pagination.
	 *
	 * @param array $filters Filters.
	 * @param int   $page    Page number.
	 * @param int   $per_page Entries per page.
	 * @return array
	 */
	public function get_entries( $filters, $page, $per_page ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$needed   = $offset + $per_page + 1;

		$date_filter = ! empty( $filters['date'] ) ? $filters['date'] : '';
		$files       = $this->writer->list_log_files( $date_filter );
		$options     = WTL_Plugin::get_options();
		$tail_bytes  = isset( $options['tail_read_bytes'] ) ? (int) $options['tail_read_bytes'] : ( 2 * MB_IN_BYTES );

		$matched         = 0;
		$entries         = array();
		$has_more        = false;
		$scanned_files   = 0;
		$scanned_entries = 0;

		foreach ( $files as $file ) {
			++$scanned_files;
			$lines = $this->read_recent_lines( $file, $tail_bytes );

			if ( empty( $lines ) ) {
				continue;
			}

			for ( $i = count( $lines ) - 1; $i >= 0; --$i ) {
				$line = trim( $lines[ $i ] );
				if ( '' === $line ) {
					continue;
				}

				++$scanned_entries;
				$entry = json_decode( $line, true );
				if ( ! is_array( $entry ) ) {
					continue;
				}

				if ( ! $this->entry_matches_filters( $entry, $filters ) ) {
					continue;
				}

				++$matched;
				if ( $matched <= $offset ) {
					continue;
				}

				if ( count( $entries ) < $per_page ) {
					$entry['_source_file'] = basename( $file );
					$entries[]             = $entry;
					continue;
				}

				$has_more = true;
				break 2;
			}

			if ( $matched >= $needed ) {
				$has_more = true;
				break;
			}
		}

		return array(
			'entries'         => $entries,
			'has_more'        => $has_more,
			'scanned_files'   => $scanned_files,
			'scanned_entries' => $scanned_entries,
		);
	}

	/**
	 * Read recent lines from tail of file.
	 *
	 * @param string $file       File path.
	 * @param int    $tail_bytes Max bytes to read from tail.
	 * @return array
	 */
	private function read_recent_lines( $file, $tail_bytes ) {
		$size = @filesize( $file );
		if ( false === $size || $size <= 0 ) {
			return array();
		}

		$start = max( 0, $size - $tail_bytes );
		$fh    = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			return array();
		}

		if ( $start > 0 ) {
			fseek( $fh, $start );
		}

		$chunk = stream_get_contents( $fh );
		fclose( $fh );

		if ( false === $chunk || '' === $chunk ) {
			return array();
		}

		// If this is a partial chunk, drop the first incomplete line.
		if ( $start > 0 ) {
			$newline_pos = strpos( $chunk, "\n" );
			if ( false !== $newline_pos ) {
				$chunk = substr( $chunk, $newline_pos + 1 );
			}
		}

		return explode( "\n", $chunk );
	}

	/**
	 * Check whether an entry passes active filters.
	 *
	 * @param array $entry   Log entry.
	 * @param array $filters Filter map.
	 * @return bool
	 */
	private function entry_matches_filters( $entry, $filters ) {
		if ( ! empty( $filters['method'] ) ) {
			$entry_method = isset( $entry['method'] ) ? strtoupper( (string) $entry['method'] ) : '';
			if ( strtoupper( $filters['method'] ) !== $entry_method ) {
				return false;
			}
		}

		if ( ! empty( $filters['status'] ) ) {
			$entry_status = isset( $entry['status_code'] ) ? (string) (int) $entry['status_code'] : '';
			if ( (string) $filters['status'] !== $entry_status ) {
				return false;
			}
		}

		if ( ! empty( $filters['route_type'] ) ) {
			$route_type = isset( $entry['route_type'] ) ? (string) $entry['route_type'] : '';
			if ( $filters['route_type'] !== $route_type ) {
				return false;
			}
		}

		if ( ! empty( $filters['date'] ) ) {
			$timestamp = isset( $entry['timestamp_utc'] ) ? (string) $entry['timestamp_utc'] : '';
			if ( 0 !== strpos( $timestamp, $filters['date'] ) ) {
				return false;
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$needle = strtolower( $filters['search'] );
			$uri    = isset( $entry['uri'] ) ? strtolower( (string) $entry['uri'] ) : '';
			$path   = isset( $entry['path'] ) ? strtolower( (string) $entry['path'] ) : '';

			if ( false === strpos( $uri, $needle ) && false === strpos( $path, $needle ) ) {
				return false;
			}
		}

		return true;
	}
}
