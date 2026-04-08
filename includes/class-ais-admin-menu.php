<?php
/**
 * Admin menu class.
 *
 * @package AIS_Seo_Squad_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin menu, settings, and approval table rendering.
 */
class AIS_Admin_Menu {

	/**
	 * Data manager.
	 *
	 * @var AIS_Data_Manager
	 */
	private $data_manager;

	/**
	 * Constructor.
	 *
	 * @param AIS_Data_Manager $data_manager Data manager instance.
	 */
	public function __construct( $data_manager ) {
		$this->data_manager = $data_manager;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers plugin menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'AI SEO Squad', 'ai-seo-squad-controller' ),
			__( 'AI SEO Squad', 'ai-seo-squad-controller' ),
			'edit_posts',
			'ais-seo-squad-controller',
			array( $this, 'render_page' ),
			'dashicons-analytics',
			58
		);

		add_submenu_page(
			'ais-seo-squad-controller',
			__( 'Analysis', 'ai-seo-squad-controller' ),
			__( 'Analysis', 'ai-seo-squad-controller' ),
			'edit_posts',
			'ais-seo-squad-controller',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'ais-seo-squad-controller',
			__( 'Settings', 'ai-seo-squad-controller' ),
			__( 'Settings', 'ai-seo-squad-controller' ),
			'edit_posts',
			'ais-seo-squad-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Loads JavaScript and CSS only on plugin page.
	 *
	 * @param string $hook_suffix Current admin page suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_ais-seo-squad-controller' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'ais-admin-style',
			AIS_PLUGIN_URL . 'assets/css/ais-admin.css',
			array(),
			AIS_VERSION
		);

		wp_enqueue_script(
			'ais-admin-script',
			AIS_PLUGIN_URL . 'assets/js/ais-admin.js',
			array( 'jquery' ),
			AIS_VERSION,
			true
		);

		wp_localize_script(
			'ais-admin-script',
			'aisAjax',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ais_ajax_nonce' ),
				'i18n'    => array(
					'errorGeneric' => __( 'Something went wrong. Please try again.', 'ai-seo-squad-controller' ),
					'auditing'     => __( 'Auditing...', 'ai-seo-squad-controller' ),
					'applying'     => __( 'Applying...', 'ai-seo-squad-controller' ),
					'audit'        => __( 'Audit', 'ai-seo-squad-controller' ),
					'apply'        => __( 'Apply', 'ai-seo-squad-controller' ),
					'critical'     => __( 'Critical issues detected', 'ai-seo-squad-controller' ),
					'noBrokenLinks'=> __( 'No broken links detected.', 'ai-seo-squad-controller' ),
				),
			)
		);
	}

	/**
	 * Renders the plugin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-seo-squad-controller' ) );
		}

		$selected_types   = $this->get_selected_post_types();
		$date_format      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$posts = get_posts(
			array(
				'post_type'      => $selected_types,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => 20,
			)
		);
		?>
		<div class="wrap ais-wrap">
			<h1><?php esc_html_e( 'AI SEO Squad Controller', 'ai-seo-squad-controller' ); ?></h1>

			<h2><?php esc_html_e( 'SEO Approval Table', 'ai-seo-squad-controller' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post Title', 'ai-seo-squad-controller' ); ?></th>
						<th><?php esc_html_e( 'URL', 'ai-seo-squad-controller' ); ?></th>
						<th><?php esc_html_e( 'AI Analysis', 'ai-seo-squad-controller' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ai-seo-squad-controller' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $posts ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No posts found.', 'ai-seo-squad-controller' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $posts as $post ) : ?>
							<?php $suggestions = $this->data_manager->get_suggestions( $post->ID ); ?>
							<?php $last_update = ! empty( $suggestions['last_update'] ) ? mysql2date( $date_format, $suggestions['last_update'] ) : '—'; ?>
							<?php $post_type_obj = get_post_type_object( $post->post_type ); ?>
							<?php $post_type_label = $post_type_obj && ! empty( $post_type_obj->labels->singular_name ) ? (string) $post_type_obj->labels->singular_name : (string) $post->post_type; ?>
							<tr data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-has-critical="<?php echo ! empty( $suggestions['has_critical_issues'] ) ? '1' : '0'; ?>">
								<td>
									<div class="ais-post-title-wrap">
										<span class="ais-post-title-text"><?php echo esc_html( get_the_title( $post->ID ) ); ?></span>
										<span class="ais-post-type-tag"><?php echo esc_html( $post_type_label ); ?></span>
									</div>
								</td>
								<td>
									<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( get_permalink( $post->ID ) ); ?>
									</a>
								</td>
								<td class="ais-tech-text">
									<div class="ais-audit-table-wrap"><?php echo wp_kses_post( $this->render_audit_table_html( $suggestions['audit_table'] ) ); ?></div>
									<p class="ais-meta-hidden" style="display:none;"><?php echo esc_html( $suggestions['meta_description'] ); ?></p>
								<div class="ais-score-wrap">
									<?php if ( $suggestions['confidence_score'] > 0 ) : ?>
										<span class="ais-badge ais-score-badge ais-score-<?php echo esc_attr( $this->score_level( $suggestions['confidence_score'] ) ); ?>"><?php echo esc_html( $suggestions['confidence_score'] ); ?>/100</span>
									<?php endif; ?>
									<?php if ( ! empty( $suggestions['recommended_action'] ) ) : ?>
										<span class="ais-badge ais-action-badge ais-action-<?php echo esc_attr( $suggestions['recommended_action'] ); ?>"><?php echo esc_html( strtoupper( $suggestions['recommended_action'] ) ); ?></span>
									<?php endif; ?>
								</div>
								<?php if ( ! empty( $suggestions['has_critical_issues'] ) ) : ?>
									<p><span class="ais-badge ais-badge-critical"><?php esc_html_e( 'Critical issues detected', 'ai-seo-squad-controller' ); ?></span></p>
								<?php endif; ?>
								<p class="ais-tech-summary"><?php echo esc_html( $suggestions['technical_fix'] ); ?></p>
								<p class="ais-claude-summary"><?php echo esc_html( $suggestions['claude_summary'] ); ?></p>
								<div class="ais-warnings-wrap"><?php echo wp_kses_post( $this->render_warnings_html( $suggestions['claude_warnings'] ) ); ?></div>
								<div class="ais-broken-links-title"><?php esc_html_e( 'Broken Links', 'ai-seo-squad-controller' ); ?></div>
									<div class="ais-broken-links-wrap"><?php echo wp_kses_post( $this->render_broken_links_html( $suggestions['broken_links'] ) ); ?></div>
								</td>
								<td class="ais-actions-cell">
									<button type="button" class="button button-secondary ais-audit-btn"><?php esc_html_e( 'Audit', 'ai-seo-squad-controller' ); ?></button>
									<button type="button" class="button button-primary ais-apply-btn" <?php disabled( empty( $suggestions['meta_description'] ) ); ?>><?php esc_html_e( 'Apply', 'ai-seo-squad-controller' ); ?></button>
									<p class="ais-last-update-wrap"><strong><?php esc_html_e( 'Last update:', 'ai-seo-squad-controller' ); ?></strong> <span class="ais-last-update"><?php echo esc_html( $last_update ); ?></span></p>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the settings page separately from analysis table.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-seo-squad-controller' ) );
		}

		$this->maybe_save_settings();
		?>
		<div class="wrap ais-wrap">
			<h1><?php esc_html_e( 'AI SEO Squad Settings', 'ai-seo-squad-controller' ); ?></h1>
			<?php $this->render_settings_form(); ?>
		</div>
		<?php
	}

	/**
	 * Saves plugin settings.
	 *
	 * @return void
	 */
	private function maybe_save_settings() {
		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$settings_action = isset( $_POST['ais_settings_action'] ) ? sanitize_key( wp_unslash( $_POST['ais_settings_action'] ) ) : '';
		if ( 'save' !== $settings_action ) {
			return;
		}

		if ( ! check_admin_referer( 'ais_save_settings', 'ais_settings_nonce' ) ) {
			return;
		}

		$api_user = sanitize_text_field( wp_unslash( $_POST['ais_api_user'] ?? '' ) );
		$api_pass = sanitize_text_field( wp_unslash( $_POST['ais_api_pass'] ?? '' ) );
		$api_token = sanitize_text_field( wp_unslash( $_POST['ais_api_token'] ?? '' ) );
		if ( '' === $api_pass ) {
			$api_pass = (string) get_option( AIS_API_Client::OPTION_API_PASS, '' );
		}
		if ( '' === $api_token ) {
			$api_token = (string) get_option( AIS_API_Client::OPTION_API_TOKEN, '' );
		}
		update_option( AIS_API_Client::OPTION_API_USER, $api_user );
		update_option( AIS_API_Client::OPTION_API_PASS, $api_pass );
		update_option( AIS_API_Client::OPTION_API_TOKEN, $api_token );

		$selected_raw = wp_unslash( $_POST['ais_content_types'] ?? array( 'post' ) );
		$selected_raw = is_array( $selected_raw ) ? $selected_raw : array( 'post' );
		$available    = array_keys( $this->get_available_post_types() );
		$selected     = array();

		foreach ( $selected_raw as $post_type_slug ) {
			$slug = sanitize_key( (string) $post_type_slug );
			if ( in_array( $slug, $available, true ) ) {
				$selected[] = $slug;
			}
		}

		if ( empty( $selected ) ) {
			$selected = array( 'post' );
		}

		$selected = array_values( array_unique( $selected ) );
		update_option( AIS_API_Client::OPTION_CONTENT_TYPES, $selected );

		$legacy_endpoint = 'posts';
		if ( in_array( 'post', $selected, true ) ) {
			$legacy_endpoint = 'posts';
		} elseif ( in_array( 'page', $selected, true ) ) {
			$legacy_endpoint = 'pages';
		} elseif ( ! empty( $selected[0] ) ) {
			$legacy_endpoint = (string) $selected[0];
		}
		update_option( AIS_API_Client::OPTION_CONTENT_ENDPOINT, $legacy_endpoint );

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'ai-seo-squad-controller' ) . '</p></div>';
	}

	/**
	 * Renders plugin settings fields.
	 *
	 * @return void
	 */
	private function render_settings_form() {
		$api_user       = (string) get_option( AIS_API_Client::OPTION_API_USER, '' );
		$api_pass       = (string) get_option( AIS_API_Client::OPTION_API_PASS, '' );
		$api_token      = (string) get_option( AIS_API_Client::OPTION_API_TOKEN, '' );
		$available      = $this->get_available_post_types();
		$selected_types = $this->get_selected_post_types();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ais-seo-squad-settings' ) ); ?>" class="ais-settings-form">
			<?php wp_nonce_field( 'ais_save_settings', 'ais_settings_nonce' ); ?>
			<input type="hidden" name="ais_settings_action" value="save" />
			<h2><?php esc_html_e( 'Credentials & Content Types', 'ai-seo-squad-controller' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ais_api_user"><?php esc_html_e( 'API User', 'ai-seo-squad-controller' ); ?></label></th>
					<td>
						<input type="text" id="ais_api_user" name="ais_api_user" class="regular-text" value="<?php echo esc_attr( $api_user ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ais_api_pass"><?php esc_html_e( 'API Password', 'ai-seo-squad-controller' ); ?></label></th>
					<td>
						<input type="password" id="ais_api_pass" name="ais_api_pass" class="regular-text" value="<?php echo esc_attr( $api_pass ); ?>" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Leave blank only if you want to keep the existing saved password.', 'ai-seo-squad-controller' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ais_api_token"><?php esc_html_e( 'API Token / Shared Secret', 'ai-seo-squad-controller' ); ?></label></th>
					<td>
						<input type="password" id="ais_api_token" name="ais_api_token" class="regular-text" value="<?php echo esc_attr( $api_token ); ?>" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'This token must match AIS_TOKEN on the backend API server.', 'ai-seo-squad-controller' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Content Types To Analyze', 'ai-seo-squad-controller' ); ?></th>
					<td>
						<?php foreach ( $available as $slug => $post_type_obj ) : ?>
							<label style="display:block; margin-bottom:6px;">
								<input type="checkbox" name="ais_content_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_types, true ) ); ?> />
								<?php echo esc_html( $post_type_obj->labels->singular_name ); ?>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Select one or more. The analysis table will include all selected content types.', 'ai-seo-squad-controller' ); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="submit" name="ais_settings_submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'ai-seo-squad-controller' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Returns all available public content types for analysis checkboxes.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	private function get_available_post_types() {
		$types  = get_post_types( array( 'public' => true, 'show_ui' => true ), 'objects' );
		$clean  = array();

		foreach ( $types as $slug => $post_type_obj ) {
			if ( in_array( $slug, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}
			$clean[ $slug ] = $post_type_obj;
		}

		if ( empty( $clean['post'] ) ) {
			$clean['post'] = get_post_type_object( 'post' );
		}

		if ( empty( $clean['page'] ) ) {
			$clean['page'] = get_post_type_object( 'page' );
		}

		return array_filter( $clean );
	}

	/**
	 * Returns selected post types for analysis.
	 *
	 * @return array<int,string>
	 */
	private function get_selected_post_types() {
		$available = array_keys( $this->get_available_post_types() );
		$saved     = get_option( AIS_API_Client::OPTION_CONTENT_TYPES, array() );
		$saved     = is_array( $saved ) ? $saved : array();

		if ( empty( $saved ) ) {
			$legacy_endpoint = sanitize_key( (string) get_option( AIS_API_Client::OPTION_CONTENT_ENDPOINT, 'posts' ) );
			if ( 'posts' === $legacy_endpoint ) {
				$saved = array( 'post' );
			} elseif ( 'pages' === $legacy_endpoint ) {
				$saved = array( 'page' );
			} elseif ( $legacy_endpoint ) {
				$saved = array( $legacy_endpoint );
			}
		}

		$selected = array();
		foreach ( $saved as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $slug, $available, true ) ) {
				$selected[] = $slug;
			}
		}

		if ( empty( $selected ) ) {
			$selected = array( 'post' );
		}

		return array_values( array_unique( $selected ) );
	}

	/**
	 * Returns a CSS modifier class based on the confidence score.
	 *
	 * @param int $score Confidence score 0-100.
	 * @return string
	 */
	private function score_level( $score ) {
		if ( $score >= 80 ) {
			return 'high';
		}
		if ( $score >= 60 ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Renders Claude warning items as an HTML list.
	 *
	 * @param array $warnings Warnings array.
	 * @return string
	 */
	private function render_warnings_html( $warnings ) {
		if ( empty( $warnings ) || ! is_array( $warnings ) ) {
			return '';
		}

		$html = '<ul class="ais-warnings-list">';
		foreach ( $warnings as $warning ) {
			$html .= '<li>' . esc_html( (string) $warning ) . '</li>';
		}
		$html .= '</ul>';

		return $html;
	}

	/**
	 * Renders Screaming Frog-style audit table.
	 *
	 * @param array $rows Audit rows from Claude.
	 * @return string
	 */
	private function render_audit_table_html( $rows ) {
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return '<p class="ais-no-issues">' . esc_html__( 'Run Audit to generate technical extraction table.', 'ai-seo-squad-controller' ) . '</p>';
		}

		$html  = '<table class="ais-audit-table">';
		$html .= '<thead><tr>';
		$html .= '<th>' . esc_html__( 'Element', 'ai-seo-squad-controller' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Extracted Value', 'ai-seo-squad-controller' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Status', 'ai-seo-squad-controller' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Technical Observation', 'ai-seo-squad-controller' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$element     = ! empty( $row['element'] ) ? esc_html( (string) $row['element'] ) : '-';
			$value       = ! empty( $row['value'] ) ? esc_html( (string) $row['value'] ) : '-';
			$status      = ! empty( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'warning';
			$observation = ! empty( $row['observation'] ) ? esc_html( (string) $row['observation'] ) : '';
			$status_text = 'success' === $status ? 'Success' : ( 'error' === $status ? 'Error' : 'Warning' );

			$html .= '<tr>';
			$html .= '<td><strong>' . $element . '</strong></td>';
			$html .= '<td>' . $value . '</td>';
			$html .= '<td><span class="ais-inline-status ais-status-' . esc_attr( $status ) . '">' . esc_html( $status_text ) . '</span></td>';
			$html .= '<td>' . $observation . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}

	/**
	 * Renders broken link details for the admin table.
	 *
	 * @param array $broken_links Broken links payload.
	 * @return string
	 */
	private function render_broken_links_html( $broken_links ) {
		if ( empty( $broken_links ) || ! is_array( $broken_links ) ) {
			return '<p class="ais-no-issues">' . esc_html__( 'No broken links detected.', 'ai-seo-squad-controller' ) . '</p>';
		}

		$html = '<ul class="ais-broken-links-list">';
		foreach ( $broken_links as $broken_link ) {
			$url       = ! empty( $broken_link['url'] ) ? esc_url( (string) $broken_link['url'] ) : '';
			$reason    = ! empty( $broken_link['reason'] ) ? esc_html( (string) $broken_link['reason'] ) : esc_html__( 'Unknown issue', 'ai-seo-squad-controller' );
			$issue_type = ! empty( $broken_link['issue_type'] ) ? esc_html( (string) $broken_link['issue_type'] ) : 'unknown';
			$html     .= '<li><strong>' . $issue_type . '</strong>: ';
			$html     .= $url ? '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' : esc_html__( 'Invalid URL', 'ai-seo-squad-controller' );
			$html     .= ' <span class="ais-broken-reason">(' . $reason . ')</span></li>';
		}
		$html .= '</ul>';

		return $html;
	}
}
