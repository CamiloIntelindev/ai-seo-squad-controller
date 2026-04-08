<?php
/**
 * Data manager class.
 *
 * @package AIS_Seo_Squad_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AI suggestion storage and approval workflow.
 */
class AIS_Data_Manager {

	/**
	 * Custom table suffix.
	 */
	const TABLE_SUFFIX = 'ais_suggestions';

	/**
	 * Pending status.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Approved status.
	 */
	const STATUS_APPROVED = 'approved';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		$instance = new self();
		$instance->create_table();
	}

	/**
	 * Creates the custom suggestions table.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			original_title text NOT NULL,
			new_meta_description text NOT NULL,
			tech_audit_json longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Inserts a new AI suggestion row.
	 *
	 * @param array<string,mixed> $data Suggestion data.
	 * @return int|false
	 */
	public function save_suggestion( $data ) {
		global $wpdb;

		$post_id              = isset( $data['post_id'] ) ? absint( $data['post_id'] ) : 0;
		$status               = $this->sanitize_status( $data['status'] ?? self::STATUS_PENDING );
		$original_title       = sanitize_text_field( (string) ( $data['original_title'] ?? '' ) );
		$new_meta_description = wp_strip_all_tags( (string) ( $data['new_meta_description'] ?? '' ) );
		$tech_audit_json      = $this->sanitize_tech_audit_json( $data['tech_audit_json'] ?? '' );

		if ( empty( $post_id ) || empty( $original_title ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$this->get_table_name(),
			array(
				'post_id'              => $post_id,
				'status'               => $status,
				'original_title'       => $original_title,
				'new_meta_description' => $new_meta_description,
				'tech_audit_json'      => $tech_audit_json,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Returns pending suggestions for the admin table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pending_suggestions() {
		global $wpdb;

		$table_name  = $this->get_table_name();
		$posts_table = $wpdb->posts;
		$query       = $wpdb->prepare(
			"SELECT s.id, s.post_id, s.status, s.original_title, s.new_meta_description, s.tech_audit_json, s.created_at, p.post_title
			FROM {$table_name} s
			LEFT JOIN {$posts_table} p ON p.ID = s.post_id
			WHERE s.status = %s
			ORDER BY s.created_at DESC",
			self::STATUS_PENDING
		);

		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Approves a suggestion and updates the WordPress SEO fields.
	 *
	 * @param int $suggestion_id Suggestion ID.
	 * @return bool
	 */
	public function approve_suggestion( $suggestion_id ) {
		global $wpdb;

		$suggestion_id = absint( $suggestion_id );
		if ( empty( $suggestion_id ) ) {
			return false;
		}

		$suggestion = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->get_table_name()} WHERE id = %d LIMIT 1",
				$suggestion_id
			),
			ARRAY_A
		);

		if ( empty( $suggestion ) || empty( $suggestion['post_id'] ) ) {
			return false;
		}

		$post_id          = absint( $suggestion['post_id'] );
		$meta_description = wp_strip_all_tags( (string) $suggestion['new_meta_description'] );

		if ( empty( $meta_description ) ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $meta_description,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return false;
		}

		update_post_meta( $post_id, 'rank_math_description', $meta_description );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );

		$updated_rows = $wpdb->update(
			$this->get_table_name(),
			array( 'status' => self::STATUS_APPROVED ),
			array( 'id' => $suggestion_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated_rows;
	}

	/**
	 * Backward-compatible wrapper for storing suggestions.
	 *
	 * @param int    $post_id          Post ID.
	 * @param string $meta_description AI meta description.
	 * @param string $technical_fix    AI technical fix.
	 * @param array  $audit_payload    Full API audit payload.
	 * @return int|false
	 */
	public function save_suggestions( $post_id, $meta_description, $technical_fix, $audit_payload = array() ) {
		$audit_payload = is_array( $audit_payload ) ? $audit_payload : array();
		if ( empty( $audit_payload ) ) {
			$audit_payload = array(
				'technical_fix' => (string) $technical_fix,
			);
		}

		return $this->save_suggestion(
			array(
				'post_id'              => absint( $post_id ),
				'status'               => self::STATUS_PENDING,
				'original_title'       => get_the_title( $post_id ),
				'new_meta_description' => $meta_description,
				'tech_audit_json'      => $audit_payload,
			)
		);
	}

	/**
	 * Backward-compatible wrapper for fetching the latest pending suggestion.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function get_suggestions( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( empty( $post_id ) ) {
			return $this->empty_suggestion_payload();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, new_meta_description, tech_audit_json, created_at
				FROM {$this->get_table_name()}
				WHERE post_id = %d AND status = %s
				ORDER BY created_at DESC, id DESC
				LIMIT 1",
				$post_id,
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return $this->empty_suggestion_payload();
		}

		$audit_data         = $this->decode_audit_json( (string) $row['tech_audit_json'] );
		$technical_fix      = ! empty( $audit_data['technical_fix'] ) ? sanitize_textarea_field( (string) $audit_data['technical_fix'] ) : '';
		$broken_links       = ! empty( $audit_data['broken_links'] ) && is_array( $audit_data['broken_links'] ) ? $audit_data['broken_links'] : array();
		$has_critical       = $this->has_critical_issues( $audit_data );
		$confidence_score   = isset( $audit_data['confidence_score'] ) ? (int) $audit_data['confidence_score'] : 0;
		$recommended_action = ! empty( $audit_data['recommended_action'] ) ? sanitize_text_field( (string) $audit_data['recommended_action'] ) : '';
		$claude_report      = ! empty( $audit_data['agent_reports']['claude_auditor'] ) && is_array( $audit_data['agent_reports']['claude_auditor'] ) ? (array) $audit_data['agent_reports']['claude_auditor'] : array();
		$claude_summary     = ! empty( $claude_report['summary'] ) ? sanitize_text_field( (string) $claude_report['summary'] ) : '';
		$claude_warnings    = ! empty( $claude_report['warnings'] ) && is_array( $claude_report['warnings'] ) ? $claude_report['warnings'] : array();

		return array(
			'suggestion_id'      => (int) $row['id'],
			'meta_description'   => (string) $row['new_meta_description'],
			'last_update'        => (string) ( $row['created_at'] ?? '' ),
			'technical_fix'      => $technical_fix,
			'tech_audit_json'    => (string) $row['tech_audit_json'],
			'broken_links'       => $broken_links,
			'has_critical_issues'=> $has_critical,
			'provider'           => ! empty( $audit_data['provider'] ) ? sanitize_text_field( (string) $audit_data['provider'] ) : '',
			'confidence_score'   => $confidence_score,
			'recommended_action' => $recommended_action,
			'claude_summary'     => $claude_summary,
			'claude_warnings'    => $claude_warnings,
			'audit_table'        => ! empty( $audit_data['audit_table'] ) && is_array( $audit_data['audit_table'] ) ? $audit_data['audit_table'] : array(),
		);
	}

	/**
	 * Returns whether the suggestion contains critical issues.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function post_has_critical_issues( $post_id ) {
		$suggestion = $this->get_suggestions( $post_id );

		return ! empty( $suggestion['has_critical_issues'] );
	}

	/**
	 * Backward-compatible wrapper that marks pending rows as approved.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false
	 */
	public function clear_suggestions( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( empty( $post_id ) ) {
			return false;
		}

		return $wpdb->update(
			$this->get_table_name(),
			array( 'status' => self::STATUS_APPROVED ),
			array(
				'post_id' => $post_id,
				'status'  => self::STATUS_PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Returns the full table name.
	 *
	 * @return string
	 */
	private function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Validates status values.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private function sanitize_status( $status ) {
		$status = sanitize_key( (string) $status );

		if ( self::STATUS_APPROVED === $status ) {
			return self::STATUS_APPROVED;
		}

		return self::STATUS_PENDING;
	}

	/**
	 * Sanitizes the technical audit JSON payload.
	 *
	 * @param mixed $tech_audit_json Raw technical audit data.
	 * @return string
	 */
	private function sanitize_tech_audit_json( $tech_audit_json ) {
		if ( is_array( $tech_audit_json ) || is_object( $tech_audit_json ) ) {
			return (string) wp_json_encode( $tech_audit_json );
		}

		return sanitize_textarea_field( (string) $tech_audit_json );
	}

	/**
	 * Decodes the technical audit JSON payload.
	 *
	 * @param string $tech_audit_json Raw JSON.
	 * @return array<string,mixed>
	 */
	private function decode_audit_json( $tech_audit_json ) {
		$decoded = json_decode( $tech_audit_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Determines whether an audit payload contains critical issues.
	 *
	 * @param array<string,mixed> $audit_data Audit payload.
	 * @return bool
	 */
	private function has_critical_issues( $audit_data ) {
		if ( ! empty( $audit_data['broken_links'] ) && is_array( $audit_data['broken_links'] ) ) {
			foreach ( $audit_data['broken_links'] as $broken_link ) {
				$issue_type = ! empty( $broken_link['issue_type'] ) ? sanitize_key( (string) $broken_link['issue_type'] ) : 'unknown';
				if ( in_array( $issue_type, array( 'http_error', 'ssl_error', 'timeout', 'connection_error', 'unknown' ), true ) ) {
					return true;
				}
			}
		}

		if ( ! empty( $audit_data['recommended_action'] ) && 'review' === $audit_data['recommended_action'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns the default empty suggestion payload.
	 *
	 * @return array<string,mixed>
	 */
	private function empty_suggestion_payload() {
		return array(
'suggestion_id'      => 0,
			'meta_description'   => '',
			'last_update'        => '',
			'technical_fix'      => '',
			'tech_audit_json'    => '',
			'broken_links'       => array(),
			'has_critical_issues'=> false,
			'provider'           => '',
			'confidence_score'   => 0,
			'recommended_action' => '',
			'claude_summary'     => '',
			'claude_warnings'    => array(),
			'audit_table'        => array(),
		);
	}
}
