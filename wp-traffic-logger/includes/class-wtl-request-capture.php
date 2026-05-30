<?php
/**
 * Request capture and log payload construction.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTL_Request_Capture {
	/**
	 * Raw request body captured once.
	 *
	 * @var string
	 */
	private $raw_body = '';

	/**
	 * Snapshot of request headers.
	 *
	 * @var array
	 */
	private $headers = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Loaded lazily only when a request is actually logged.
	}

	/**
	 * Build high-level request context.
	 *
	 * @return array
	 */
	public function get_request_context() {
		$is_rest  = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$is_ajax  = defined( 'DOING_AJAX' ) && DOING_AJAX;
		$is_admin = is_admin();

		$route_type = 'public';
		if ( $is_rest ) {
			$route_type = 'rest';
		} elseif ( $is_ajax ) {
			$route_type = 'ajax';
		} elseif ( $is_admin ) {
			$route_type = 'admin';
		}

		return array(
			'is_rest'    => $is_rest,
			'is_ajax'    => $is_ajax,
			'is_admin'   => $is_admin,
			'route_type' => $route_type,
		);
	}

	/**
	 * Decide whether this request should be logged.
	 *
	 * @param array $context Request context.
	 * @param array $options Plugin options.
	 * @return bool
	 */
	public function should_log( $context, $options ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}

		$enabled = false;
		if ( 'rest' === $context['route_type'] ) {
			$enabled = ! empty( $options['capture_rest'] );
		} elseif ( 'ajax' === $context['route_type'] ) {
			$enabled = ! empty( $options['capture_ajax'] );
		} elseif ( 'public' === $context['route_type'] ) {
			$enabled = ! empty( $options['capture_public'] );
		}

		if ( ! $enabled ) {
			return false;
		}

		return $this->passes_sample_rate( $options );
	}

	/**
	 * Apply the configured sampling probability.
	 *
	 * @param array $options Plugin options.
	 * @return bool
	 */
	private function passes_sample_rate( $options ) {
		$rate = isset( $options['sample_rate'] ) ? (float) $options['sample_rate'] : 1.0;

		if ( $rate >= 1.0 ) {
			return true;
		}

		if ( $rate <= 0.0 ) {
			return false;
		}

		return ( wp_rand( 1, 1000000 ) / 1000000 ) <= $rate;
	}

	/**
	 * Build a complete log entry.
	 *
	 * @param array $context       Request context.
	 * @param float $request_start Request start timestamp.
	 * @param array $options       Plugin options.
	 * @return array
	 */
	public function build_log_entry( $context, $request_start, $options ) {
		$method      = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$uri         = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$scheme      = is_ssl() ? 'https' : 'http';
		$query       = wp_parse_url( $uri, PHP_URL_QUERY );
		$path        = wp_parse_url( $uri, PHP_URL_PATH );
		$status_code = (int) http_response_code();
		$user_id     = get_current_user_id();
		$now         = microtime( true );
		$max_bytes   = isset( $options['max_body_bytes'] ) ? (int) $options['max_body_bytes'] : 64 * KB_IN_BYTES;
		$scalar_cap  = (int) min( 4096, max( 256, floor( $max_bytes / 4 ) ) );
		if ( empty( $this->headers ) ) {
			$this->headers = $this->collect_headers();
		}

		if ( '' === $this->raw_body ) {
			$this->raw_body = $this->read_raw_body();
		}

		$headers = $this->mask_and_truncate_headers( $this->headers, $scalar_cap );

		$query_params = $this->truncate_scalars_deep(
			$this->mask_recursive( $this->unslash_deep( $_GET ) ),
			$scalar_cap
		);

		$post_params = $this->truncate_scalars_deep(
			$this->mask_recursive( $this->unslash_deep( $_POST ) ),
			$scalar_cap
		);

		$cookie_params = $this->truncate_scalars_deep(
			$this->mask_cookies( $this->unslash_deep( $_COOKIE ) ),
			$scalar_cap
		);

		$raw_body = $this->truncate_string( (string) $this->raw_body, $max_bytes );
		$raw_body = $this->mask_auth_material_in_text( $raw_body );

		$entry = array(
			'timestamp_utc'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'request_id'      => $this->generate_request_id(),
			'route_type'      => $context['route_type'],
			'method'          => strtoupper( (string) $method ),
			'scheme'          => $scheme,
			'host'            => sanitize_text_field( (string) $host ),
			'uri'             => $this->truncate_string( (string) $uri, 8192 ),
			'path'            => $this->truncate_string( (string) $path, 4096 ),
			'query_string'    => $this->truncate_string( (string) $query, 4096 ),
			'query_params'    => $query_params,
			'body_params'     => $post_params,
			'raw_body'        => $raw_body,
			'cookies'         => $cookie_params,
			'headers'         => $headers,
			'files'           => $this->extract_files_meta(),
			'ip'              => $this->get_client_ip( ! empty( $options['trust_proxy'] ) ),
			'user_agent'      => $this->truncate_string( $this->header_or_server( 'User-Agent', 'HTTP_USER_AGENT' ), 1024 ),
			'referer'         => $this->truncate_string( $this->header_or_server( 'Referer', 'HTTP_REFERER' ), 2048 ),
			'user_id'         => $user_id ? (int) $user_id : null,
			'status_code'     => $status_code,
			'duration_ms'     => round( ( $now - $request_start ) * 1000, 3 ),
			'peak_memory'     => (int) memory_get_peak_usage( true ),
			'fatal'           => $this->get_fatal_error(),
			'truncated_notes' => array(
				'max_body_bytes' => $max_bytes,
				'scalar_cap'     => $scalar_cap,
			),
		);

		return $entry;
	}

	/**
	 * Read request body once.
	 *
	 * @return string
	 */
	private function read_raw_body() {
		$body = file_get_contents( 'php://input' );
		if ( false === $body ) {
			return '';
		}

		return (string) $body;
	}

	/**
	 * Collect request headers with fallback.
	 *
	 * @return array
	 */
	private function collect_headers() {
		$headers = array();

		if ( function_exists( 'getallheaders' ) ) {
			$raw_headers = getallheaders();
			if ( is_array( $raw_headers ) ) {
				foreach ( $raw_headers as $name => $value ) {
					$headers[ (string) $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				}
			}
		}

		if ( empty( $headers ) ) {
			foreach ( $_SERVER as $key => $value ) {
				if ( 0 !== strpos( $key, 'HTTP_' ) || ! is_scalar( $value ) ) {
					continue;
				}

				$name             = str_replace( '_', '-', substr( $key, 5 ) );
				$headers[ $name ] = (string) $value;
			}
		}

		return $headers;
	}

	/**
	 * Mask secrets in headers and truncate large values.
	 *
	 * @param array $headers Raw headers.
	 * @param int   $scalar_cap Max scalar bytes.
	 * @return array
	 */
	private function mask_and_truncate_headers( $headers, $scalar_cap ) {
		$masked_headers = array();
		$always_mask    = array(
			'authorization',
			'cookie',
			'set-cookie',
		);

		foreach ( $headers as $name => $value ) {
			$key = strtolower( (string) $name );

			if ( in_array( $key, $always_mask, true ) || $this->is_sensitive_key( $key ) ) {
				$masked_headers[ $name ] = '***redacted***';
				continue;
			}

			$masked_headers[ $name ] = $this->truncate_string( (string) $value, $scalar_cap );
		}

		return $masked_headers;
	}

	/**
	 * Return fatal error details when relevant.
	 *
	 * @return array|null
	 */
	private function get_fatal_error() {
		$error = error_get_last();
		if ( empty( $error ) || ! is_array( $error ) ) {
			return null;
		}

		$fatal_types = array(
			E_ERROR,
			E_PARSE,
			E_CORE_ERROR,
			E_COMPILE_ERROR,
			E_USER_ERROR,
		);

		if ( empty( $error['type'] ) || ! in_array( (int) $error['type'], $fatal_types, true ) ) {
			return null;
		}

		return array(
			'type'    => (int) $error['type'],
			'message' => isset( $error['message'] ) ? $this->truncate_string( (string) $error['message'], 2048 ) : '',
			'file'    => isset( $error['file'] ) ? $this->truncate_string( (string) $error['file'], 1024 ) : '',
			'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
		);
	}

	/**
	 * Generate request ID.
	 *
	 * @return string
	 */
	private function generate_request_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return uniqid( 'wtl_', true );
	}

	/**
	 * Best-effort client IP.
	 *
	 * X-Forwarded-For is client-spoofable, so it is only consulted when the
	 * site operator has explicitly opted in via the trust_proxy option.
	 *
	 * @param bool $trust_proxy Whether to honour forwarded-for headers.
	 * @return string
	 */
	private function get_client_ip( $trust_proxy = false ) {
		if ( $trust_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidate = trim( $forwarded[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$remote = trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
				return $remote;
			}
		}

		return '';
	}

	/**
	 * Return a header value or server fallback.
	 *
	 * @param string $header_name Header key.
	 * @param string $server_key  $_SERVER fallback.
	 * @return string
	 */
	private function header_or_server( $header_name, $server_key ) {
		foreach ( $this->headers as $name => $value ) {
			if ( strtolower( $name ) === strtolower( $header_name ) ) {
				return (string) $value;
			}
		}

		if ( ! empty( $_SERVER[ $server_key ] ) ) {
			return (string) wp_unslash( $_SERVER[ $server_key ] );
		}

		return '';
	}

	/**
	 * Recursively mask sensitive keys.
	 *
	 * @param mixed  $value Value to process.
	 * @param string $key   Current key.
	 * @return mixed
	 */
	private function mask_recursive( $value, $key = '' ) {
		if ( is_array( $value ) ) {
			$masked = array();
			foreach ( $value as $child_key => $child_value ) {
				$child_key_string         = is_string( $child_key ) ? $child_key : (string) $child_key;
				$masked[ $child_key ] = $this->mask_recursive( $child_value, $child_key_string );
			}
			return $masked;
		}

		if ( is_object( $value ) ) {
			return $this->mask_recursive( (array) $value, $key );
		}

		if ( $this->is_sensitive_key( $key ) ) {
			return '***redacted***';
		}

		return $value;
	}

	/**
	 * Mask session/auth cookie values.
	 *
	 * Cookie names alone are kept; values for WordPress auth/session cookies
	 * (and any generically sensitive names) are redacted. WordPress auth cookie
	 * names such as wordpress_logged_in_<hash> do not contain the generic
	 * sensitive keywords, so they are matched here explicitly to avoid leaking
	 * live session tokens into the log files.
	 *
	 * @param array $cookies Cookie name => value map.
	 * @return array
	 */
	private function mask_cookies( $cookies ) {
		if ( ! is_array( $cookies ) ) {
			return array();
		}

		$masked = array();
		foreach ( $cookies as $name => $value ) {
			$key = strtolower( (string) $name );

			if (
				$this->is_sensitive_key( $key )
				|| 0 === strpos( $key, 'wordpress' )
				|| 0 === strpos( $key, 'wp-settings' )
				|| 0 === strpos( $key, 'wp_' )
				|| 0 === strpos( $key, 'comment_author' )
				|| 0 === strpos( $key, 'woocommerce' )
			) {
				$masked[ $name ] = '***redacted***';
				continue;
			}

			$masked[ $name ] = $value;
		}

		return $masked;
	}

	/**
	 * Detect sensitive key names.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		if ( '' === $key ) {
			return false;
		}

		return (bool) preg_match( '/(pass(word)?|token|secret|nonce|api[_-]?key|session|auth|cookie|bearer)/i', $key );
	}

	/**
	 * Recursively unslash arrays/scalars.
	 *
	 * @param mixed $value Input value.
	 * @return mixed
	 */
	private function unslash_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->unslash_deep( $item );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return wp_unslash( $value );
		}

		return $value;
	}

	/**
	 * Truncate scalar strings recursively.
	 *
	 * @param mixed $value Value to process.
	 * @param int   $max_bytes Max bytes per scalar.
	 * @return mixed
	 */
	private function truncate_scalars_deep( $value, $max_bytes ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->truncate_scalars_deep( $item, $max_bytes );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return $this->truncate_string( $value, $max_bytes );
		}

		return $value;
	}

	/**
	 * Truncate string and annotate.
	 *
	 * @param string $value Input string.
	 * @param int    $max_bytes Max bytes.
	 * @return string
	 */
	private function truncate_string( $value, $max_bytes ) {
		$value = (string) $value;

		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}

		// Cut on a UTF-8 character boundary so truncation cannot produce
		// invalid byte sequences that break JSON encoding downstream.
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $max_bytes, 'UTF-8' ) . '...[TRUNCATED]';
		}

		return substr( $value, 0, $max_bytes ) . '...[TRUNCATED]';
	}

	/**
	 * Replace common auth material in freeform text.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function mask_auth_material_in_text( $text ) {
		$text = (string) $text;

		$replacements = array(
			// Header style: "Authorization: ..." / "Cookie: ...".
			'/(authorization\s*:\s*)[^\r\n]+/i' => '$1***redacted***',
			'/(cookie\s*:\s*)[^\r\n;]+/i' => '$1***redacted***',
			// JSON style: "key":"value" for common secret-bearing keys.
			'/("(?:authorization|cookie|pass(?:word)?|token|secret|nonce|api[_-]?key|client_secret|refresh_token|access_token|private_key|session|auth|bearer)"\s*:\s*")[^"]*(")/i' => '$1***redacted***$2',
			// Form-urlencoded style: key=value (stop at & or whitespace).
			'/((?:^|[?&])(?:pass(?:word)?|token|secret|nonce|api[_-]?key|client_secret|refresh_token|access_token|private_key|session|auth|bearer)=)[^&\s]+/i' => '$1***redacted***',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		return $text;
	}

	/**
	 * Extract upload metadata without file content.
	 *
	 * @return array
	 */
	private function extract_files_meta() {
		if ( empty( $_FILES ) || ! is_array( $_FILES ) ) {
			return array();
		}

		$files = array();
		foreach ( $_FILES as $field => $descriptor ) {
			$files[ $field ] = $this->normalize_file_descriptor( $descriptor );
		}

		return $files;
	}

	/**
	 * Normalize a single $_FILES descriptor.
	 *
	 * @param mixed $descriptor File descriptor.
	 * @return mixed
	 */
	private function normalize_file_descriptor( $descriptor ) {
		if ( ! is_array( $descriptor ) ) {
			return array();
		}

		if ( isset( $descriptor['name'] ) && is_array( $descriptor['name'] ) ) {
			return $this->normalize_multi_upload( $descriptor );
		}

		if ( isset( $descriptor['name'] ) ) {
			return array(
				'name'  => sanitize_file_name( (string) $descriptor['name'] ),
				'type'  => isset( $descriptor['type'] ) ? (string) $descriptor['type'] : '',
				'size'  => isset( $descriptor['size'] ) ? (int) $descriptor['size'] : 0,
				'error' => isset( $descriptor['error'] ) ? (int) $descriptor['error'] : 0,
			);
		}

		$out = array();
		foreach ( $descriptor as $key => $value ) {
			$out[ $key ] = $this->normalize_file_descriptor( $value );
		}

		return $out;
	}

	/**
	 * Normalize nested multi-upload descriptor.
	 *
	 * @param array $descriptor Multi-file descriptor.
	 * @return array
	 */
	private function normalize_multi_upload( $descriptor ) {
		$normalized = array();
		$names      = isset( $descriptor['name'] ) ? $descriptor['name'] : array();

		foreach ( $names as $index => $name ) {
			if ( is_array( $name ) ) {
				$normalized[ $index ] = $this->normalize_multi_upload(
					array(
						'name'  => $descriptor['name'][ $index ],
						'type'  => isset( $descriptor['type'][ $index ] ) ? $descriptor['type'][ $index ] : array(),
						'size'  => isset( $descriptor['size'][ $index ] ) ? $descriptor['size'][ $index ] : array(),
						'error' => isset( $descriptor['error'][ $index ] ) ? $descriptor['error'][ $index ] : array(),
					)
				);
				continue;
			}

			$normalized[ $index ] = array(
				'name'  => sanitize_file_name( (string) $name ),
				'type'  => isset( $descriptor['type'][ $index ] ) ? (string) $descriptor['type'][ $index ] : '',
				'size'  => isset( $descriptor['size'][ $index ] ) ? (int) $descriptor['size'][ $index ] : 0,
				'error' => isset( $descriptor['error'][ $index ] ) ? (int) $descriptor['error'][ $index ] : 0,
			);
		}

		return $normalized;
	}
}
