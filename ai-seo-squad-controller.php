<?php
/**
 * Plugin Name: AI SEO Squad Controller
 * Plugin URI: https://example.com
 * Description: Professional workflow to review and apply AI SEO suggestions from a remote Python API.
 * Version: 1.0.0
 * Author: AI SEO Squad
 * Text Domain: ai-seo-squad-controller
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package AIS_Seo_Squad_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIS_VERSION', '1.0.0' );
define( 'AIS_PLUGIN_FILE', __FILE__ );
define( 'AIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIS_PLUGIN_DIR . 'includes/class-ais-data-manager.php';
require_once AIS_PLUGIN_DIR . 'includes/class-ais-api-client.php';
require_once AIS_PLUGIN_DIR . 'includes/class-ais-admin-menu.php';
require_once AIS_PLUGIN_DIR . 'includes/class-ais-ajax-handler.php';

register_activation_hook( AIS_PLUGIN_FILE, array( 'AIS_Data_Manager', 'activate' ) );

/**
 * Bootstraps plugin dependencies.
 */
final class AIS_Plugin {

	/**
	 * Holds plugin instance.
	 *
	 * @var AIS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Initializes plugin singleton.
	 *
	 * @return AIS_Plugin
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$data_manager = new AIS_Data_Manager();
		$api_client   = new AIS_API_Client();

		new AIS_Admin_Menu( $data_manager );
		new AIS_Ajax_Handler( $api_client, $data_manager );
	}
}

add_action( 'plugins_loaded', array( 'AIS_Plugin', 'init' ) );