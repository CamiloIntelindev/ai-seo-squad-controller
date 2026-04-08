<?php
/**
 * API client class.
 *
 * @package AIS_Seo_Squad_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends secure requests to the remote Python API.
 */
class AIS_API_Client {

	/**
	 * Backward-compatible option key for API base URL.
	 */
	const OPTION_API_BASE_URL = 'ais_api_base_url';

	/**
	 * Backward-compatible option key for shared secret.
	 */
	const OPTION_API_SHARED_SECRET = 'ais_api_shared_secret';

	/**
	 * Option key for REST endpoint slug (posts/pages/custom type).
	 */
	const OPTION_CONTENT_ENDPOINT = 'ais_content_endpoint';

	/**
	 * Option key for API username.
	 */
	const OPTION_API_USER = 'ais_api_user';

	/**
	 * Option key for API password/token.
	 */
	const OPTION_API_PASS = 'ais_api_pass';

	/**
	 * Option key for explicit backend API token.
	 */
	const OPTION_API_TOKEN = 'ais_api_token';

	/**
	 * Option key for selected post types in analysis table.
	 */
	const OPTION_CONTENT_TYPES = 'ais_content_types';

	/**
	 * API endpoint URL fallback.
	 */
	private $default_api_url = 'http://187.124.80.85/analyze';

	/**
	 * Shared agency token fallback.
	 */
	private $default_token = 'WSOSyKOFptR6fLOH9RotNXhcMis1bl4z98VrLNu2e0fadb46';

	/**
	 * Sends post data to the agency API.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @param string $url     Post URL.
	 * @return array<string,mixed>|WP_Error
	 */
	public function send_to_agency( $post_id, $title, $content, $url ) {
		$api_url = (string) get_option( self::OPTION_API_BASE_URL, $this->default_api_url );
		$api_user = (string) get_option( self::OPTION_API_USER, '' );
		$token    = (string) get_option( self::OPTION_API_TOKEN, '' );

		if ( '' === $token ) {
			$token = (string) get_option( self::OPTION_API_PASS, '' );
		}

		if ( '' === $token ) {
			$token = (string) get_option( self::OPTION_API_SHARED_SECRET, $this->default_token );
		}

		$api_url = $api_url ? $api_url : $this->default_api_url;
		$token   = $token ? $token : $this->default_token;

		$payload = array(
			'post_id' => (int) $post_id,
			'title'   => (string) $title,
			'content' => (string) $content,
			'url'     => (string) $url,
		);

		$response = wp_remote_post(
			$api_url,
			array(
				'method'      => 'POST',
				'timeout'     => 60,
				'redirection' => 3,
				'headers'     => array(
					'X-AIS-Token'  => $token,
					'X-AIS-User'   => $api_user,
					'Content-Type' => 'application/json',
				),
				'body'        => json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new WP_Error(
				'ais_api_http_error',
				sprintf( __( 'API error: HTTP %d', 'ai-seo-squad-controller' ), $status_code ),
				array(
					'status_code' => $status_code,
					'body'        => $raw_body,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'ais_api_invalid_json',
				__( 'Invalid JSON response from API.', 'ai-seo-squad-controller' )
			);
		}

		return $decoded;
	}

	/**
	 * Calls the remote audit endpoint.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string,mixed>
	 */
	public function audit_post( $post ) {
		$result = $this->send_to_agency(
			$post->ID,
			get_the_title( $post->ID ),
			(string) $post->post_content,
			get_permalink( $post->ID )
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'data'    => $result,
		);
	}
}
