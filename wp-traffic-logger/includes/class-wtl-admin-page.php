<?php
/**
 * Admin dashboard page renderer.
 *
 * @package WP_Traffic_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTL_Admin_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wtl-traffic-logs';

	/**
	 * Log reader service.
	 *
	 * @var WTL_Log_Reader
	 */
	private $reader;

	/**
	 * Hook suffix for enqueue targeting.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param WTL_Log_Reader $reader Reader.
	 */
	public function __construct( $reader ) {
		$this->reader = $reader;
	}

	/**
	 * Register menu item under Tools.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = add_management_page(
			__( 'Traffic Logs', 'wp-traffic-logger' ),
			__( 'Traffic Logs', 'wp-traffic-logger' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for plugin page.
	 *
	 * @param string $hook Current screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wtl-admin',
			WTL_PLUGIN_URL . 'assets/admin.css',
			array(),
			WTL_VERSION
		);

		wp_enqueue_script(
			'wtl-admin',
			WTL_PLUGIN_URL . 'assets/admin.js',
			array(),
			WTL_VERSION,
			true
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view traffic logs.', 'wp-traffic-logger' ) );
		}

		$filters = $this->get_filters_from_request();
		$page    = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$page    = max( 1, $page );
		$per_page = 20;

		$result = $this->reader->get_entries( $filters, $page, $per_page );
		$items  = isset( $result['entries'] ) ? $result['entries'] : array();

		$base_url  = add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
			),
			admin_url( 'tools.php' )
		);
		$reset_url = $base_url;
		?>
		<div class="wrap wtl-wrap">
			<h1><?php esc_html_e( 'WP Traffic Logger', 'wp-traffic-logger' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Logs are stored in uploads/wp-traffic-logger with best-effort web access hardening. Treat server file permissions as the primary control.', 'wp-traffic-logger' ); ?>
			</p>

			<form method="get" class="wtl-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label>
					<span><?php esc_html_e( 'Date', 'wp-traffic-logger' ); ?></span>
					<input type="date" name="date" value="<?php echo esc_attr( $filters['date'] ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Method', 'wp-traffic-logger' ); ?></span>
					<select name="method">
						<?php $this->render_select_options( array( '', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' ), $filters['method'] ); ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Status', 'wp-traffic-logger' ); ?></span>
					<input type="number" min="100" max="599" step="1" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>" />
				</label>
				<label>
					<span><?php esc_html_e( 'Route', 'wp-traffic-logger' ); ?></span>
					<select name="route_type">
						<?php $this->render_select_options( array( '', 'public', 'rest', 'ajax' ), $filters['route_type'] ); ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'URL contains', 'wp-traffic-logger' ); ?></span>
					<input type="text" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" />
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'wp-traffic-logger' ); ?></button>
				<a class="button" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Reset', 'wp-traffic-logger' ); ?></a>
			</form>

			<table class="widefat striped wtl-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time (UTC)', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'Method', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'Route', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'Path', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'IP', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'Duration (ms)', 'wp-traffic-logger' ); ?></th>
						<th><?php esc_html_e( 'Details', 'wp-traffic-logger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $items ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No matching log entries found.', 'wp-traffic-logger' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $items as $entry ) : ?>
							<?php
							$encoded_payload = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
							if ( false === $encoded_payload ) {
								$encoded_payload = '{}';
							}
							$encoded_payload = base64_encode( $encoded_payload );
							?>
							<tr>
								<td><?php echo esc_html( isset( $entry['timestamp_utc'] ) ? (string) $entry['timestamp_utc'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['method'] ) ? (string) $entry['method'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['status_code'] ) ? (string) $entry['status_code'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['route_type'] ) ? (string) $entry['route_type'] : '' ); ?></td>
								<td class="wtl-path"><?php echo esc_html( isset( $entry['path'] ) ? (string) $entry['path'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['ip'] ) ? (string) $entry['ip'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['duration_ms'] ) ? (string) $entry['duration_ms'] : '' ); ?></td>
								<td>
									<button type="button" class="button wtl-view-entry" data-entry="<?php echo esc_attr( $encoded_payload ); ?>">
										<?php esc_html_e( 'View', 'wp-traffic-logger' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php $this->render_pagination( $base_url, $filters, $page, ! empty( $result['has_more'] ) ); ?>
		</div>

		<div id="wtl-detail-backdrop" class="wtl-detail-backdrop" hidden></div>
		<aside id="wtl-detail-drawer" class="wtl-detail-drawer" hidden>
			<div class="wtl-detail-header">
				<h2><?php esc_html_e( 'Log Entry Details', 'wp-traffic-logger' ); ?></h2>
				<button type="button" class="button" id="wtl-detail-close"><?php esc_html_e( 'Close', 'wp-traffic-logger' ); ?></button>
			</div>
			<pre id="wtl-detail-content" class="wtl-detail-content"></pre>
		</aside>
		<?php
	}

	/**
	 * Render pagination links.
	 *
	 * @param string $base_url Base page URL.
	 * @param array  $filters Filters.
	 * @param int    $page Current page.
	 * @param bool   $has_more Has next page.
	 * @return void
	 */
	private function render_pagination( $base_url, $filters, $page, $has_more ) {
		$base_args = array(
			'page'       => self::PAGE_SLUG,
			'date'       => $filters['date'],
			'method'     => $filters['method'],
			'status'     => $filters['status'],
			'route_type' => $filters['route_type'],
			'search'     => $filters['search'],
		);
		?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php if ( $page > 1 ) : ?>
					<?php
					$prev_args          = $base_args;
					$prev_args['paged'] = $page - 1;
					$prev_url           = add_query_arg( $this->filter_empty_query_args( $prev_args ), $base_url );
					?>
					<a class="button" href="<?php echo esc_url( $prev_url ); ?>"><?php esc_html_e( 'Previous', 'wp-traffic-logger' ); ?></a>
				<?php endif; ?>

				<span class="wtl-page-indicator">
					<?php
					printf(
						/* translators: %d is page number. */
						esc_html__( 'Page %d', 'wp-traffic-logger' ),
						(int) $page
					);
					?>
				</span>

				<?php if ( $has_more ) : ?>
					<?php
					$next_args          = $base_args;
					$next_args['paged'] = $page + 1;
					$next_url           = add_query_arg( $this->filter_empty_query_args( $next_args ), $base_url );
					?>
					<a class="button" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Next', 'wp-traffic-logger' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render select options with selected value.
	 *
	 * @param array  $choices Choices.
	 * @param string $selected Selected value.
	 * @return void
	 */
	private function render_select_options( $choices, $selected ) {
		foreach ( $choices as $choice ) {
			$label = '' === $choice ? __( 'Any', 'wp-traffic-logger' ) : $choice;
			?>
			<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $selected, $choice ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
			<?php
		}
	}

	/**
	 * Read and sanitize filters from query string.
	 *
	 * @return array
	 */
	private function get_filters_from_request() {
		// Read-only listing filters; no state is changed, so no nonce is required.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$raw = array(
			'date'       => isset( $_GET['date'] ) ? wp_unslash( $_GET['date'] ) : '',
			'method'     => isset( $_GET['method'] ) ? wp_unslash( $_GET['method'] ) : '',
			'status'     => isset( $_GET['status'] ) ? wp_unslash( $_GET['status'] ) : '',
			'route_type' => isset( $_GET['route_type'] ) ? wp_unslash( $_GET['route_type'] ) : '',
			'search'     => isset( $_GET['search'] ) ? wp_unslash( $_GET['search'] ) : '',
		);

		$date = sanitize_text_field( $raw['date'] );
		if ( $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = '';
		}

		$method = strtoupper( sanitize_text_field( $raw['method'] ) );
		if ( $method && ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' ), true ) ) {
			$method = '';
		}

		$status = preg_replace( '/[^0-9]/', '', sanitize_text_field( $raw['status'] ) );
		if ( $status ) {
			$status_int = (int) $status;
			if ( $status_int < 100 || $status_int > 599 ) {
				$status = '';
			}
		}

		$route = sanitize_key( $raw['route_type'] );
		if ( $route && ! in_array( $route, array( 'public', 'rest', 'ajax' ), true ) ) {
			$route = '';
		}

		$search = sanitize_text_field( $raw['search'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'date'       => $date,
			'method'     => $method,
			'status'     => $status,
			'route_type' => $route,
			'search'     => $search,
		);
	}

	/**
	 * Remove null/empty-string query values.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	private function filter_empty_query_args( $args ) {
		$filtered = array();
		foreach ( $args as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			$filtered[ $key ] = $value;
		}
		return $filtered;
	}
}
