<?php
/**
 * AJAX handler class.
 *
 * @package AIS_Seo_Squad_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles audit/apply actions without page reload.
 */
class AIS_Ajax_Handler {

	/**
	 * API client.
	 *
	 * @var AIS_API_Client
	 */
	private $api_client;

	/**
	 * Data manager.
	 *
	 * @var AIS_Data_Manager
	 */
	private $data_manager;

	/**
	 * Constructor.
	 *
	 * @param AIS_API_Client   $api_client   API client.
	 * @param AIS_Data_Manager $data_manager Data manager.
	 */
	public function __construct( $api_client, $data_manager ) {
		$this->api_client   = $api_client;
		$this->data_manager = $data_manager;

		add_action( 'wp_ajax_ais_run_audit', array( $this, 'run_audit' ) );
		add_action( 'wp_ajax_ais_apply_suggestion', array( $this, 'apply_suggestion' ) );
	}

	/**
	 * Runs AI audit for a post and stores suggestions.
	 *
	 * @return void
	 */
	public function run_audit() {
		try {
			$this->validate_request();

			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			$post    = get_post( $post_id );
			$allowed_types = $this->get_configured_post_types();
			if ( ! $post || ! in_array( $post->post_type, $allowed_types, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid post selected.', 'ai-seo-squad-controller' ) ), 400 );
			}

			$result = $this->api_client->audit_post( $post );
			if ( empty( $result['success'] ) ) {
				$message = ! empty( $result['message'] ) ? (string) $result['message'] : __( 'Audit failed.', 'ai-seo-squad-controller' );
				wp_send_json_error( array( 'message' => $message ), 500 );
			}

			$data               = (array) $result['data'];
			$meta_description   = sanitize_text_field( (string) ( $data['meta_description'] ?? '' ) );
			$technical_fix      = sanitize_textarea_field( (string) ( $data['technical_fix'] ?? '' ) );
			$broken_links       = ! empty( $data['broken_links'] ) && is_array( $data['broken_links'] ) ? $data['broken_links'] : array();
			$confidence_score   = isset( $data['confidence_score'] ) ? (int) $data['confidence_score'] : 100;
			$recommended_action = sanitize_text_field( (string) ( $data['recommended_action'] ?? 'apply' ) );
			$claude_report      = ! empty( $data['agent_reports']['claude_auditor'] ) && is_array( $data['agent_reports']['claude_auditor'] ) ? (array) $data['agent_reports']['claude_auditor'] : array();
			$claude_summary     = sanitize_text_field( (string) ( $claude_report['summary'] ?? '' ) );
			$claude_warnings    = ! empty( $claude_report['warnings'] ) && is_array( $claude_report['warnings'] ) ? $claude_report['warnings'] : array();
			$has_critical       = ! empty( $broken_links ) || 'review' === $recommended_action;
			$audit_table        = ! empty( $data['audit_table'] ) && is_array( $data['audit_table'] ) ? $data['audit_table'] : array();
			$meta_suggestion    = sanitize_text_field( (string) ( $data['meta_description_suggestion'] ?? $meta_description ) );

			// Keep the previous stored table when the new audit payload has no table rows.
			if ( empty( $audit_table ) ) {
				$previous_suggestion = $this->data_manager->get_suggestions( $post_id );
				if ( ! empty( $previous_suggestion['audit_table'] ) && is_array( $previous_suggestion['audit_table'] ) ) {
					$audit_table         = $previous_suggestion['audit_table'];
					$data['audit_table'] = $audit_table;
				}
			}

			if ( empty( $meta_suggestion ) && empty( $audit_table ) ) {
				wp_send_json_error( array( 'message' => __( 'Audit returned empty suggestions.', 'ai-seo-squad-controller' ) ), 500 );
			}

			$saved = $this->data_manager->save_suggestions( $post_id, $meta_suggestion, $technical_fix, $data );
			if ( false === $saved ) {
				wp_send_json_error( array( 'message' => __( 'Audit completed, but the suggestion could not be stored locally.', 'ai-seo-squad-controller' ) ), 500 );
			}

			$this->data_manager->reset_adjustment_applied( $post_id );

			wp_send_json_success(
				array(
					'meta_description'   => $meta_suggestion,
					'last_update'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					'technical_fix'      => $technical_fix,
					'broken_links'       => $broken_links,
					'has_critical_issues'=> $has_critical,
					'confidence_score'   => $confidence_score,
					'recommended_action' => $recommended_action,
					'claude_summary'     => $claude_summary,
					'claude_warnings'    => $claude_warnings,
					'audit_table'        => $audit_table,
					'adjustment_applied' => false,
				)
			);
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Applies pending AI suggestion to post excerpt.
	 *
	 * @return void
	 */
	public function apply_suggestion() {
		try {
			$this->validate_request();

			$post_id      = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			$suggestions  = $this->data_manager->get_suggestions( $post_id );
			$meta_excerpt = (string) $suggestions['meta_description'];

			if ( empty( $post_id ) || empty( $meta_excerpt ) ) {
				wp_send_json_error( array( 'message' => __( 'No pending meta description to apply.', 'ai-seo-squad-controller' ) ), 400 );
			}

			$updated = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => $meta_excerpt,
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => $updated->get_error_message() ), 500 );
			}

			$this->data_manager->clear_suggestions( $post_id );
			$this->data_manager->mark_adjustment_applied( $post_id, $meta_excerpt );

			wp_send_json_success(
				array(
					'message'            => __( 'AI suggestion applied successfully.', 'ai-seo-squad-controller' ),
					'adjustment_applied' => true,
				)
			);
		} catch ( \Throwable $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * Validates nonce and user permissions.
	 *
	 * @return void
	 */
	private function validate_request() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ai-seo-squad-controller' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ais_ajax_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'ai-seo-squad-controller' ) ), 403 );
		}
	}

	/**
	 * Returns configured WordPress post types from multi-select setting.
	 *
	 * @return array<int,string>
	 */
	private function get_configured_post_types() {
		$saved = get_option( AIS_API_Client::OPTION_CONTENT_TYPES, array( 'post' ) );
		$saved = is_array( $saved ) ? $saved : array( 'post' );

		$allowed = array();
		foreach ( $saved as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug ) {
				$allowed[] = $slug;
			}
		}

		if ( empty( $allowed ) ) {
			$allowed = array( 'post' );
		}

		return array_values( array_unique( $allowed ) );
	}
}
