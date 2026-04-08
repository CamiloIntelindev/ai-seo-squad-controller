<?php
/**
 * Schema manager class.
 *
 * @package AIS_Seo_Squad_Controller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects AI-managed schema into supported SEO providers or outputs fallback JSON-LD.
 */
class AIS_Schema_Manager {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( $this->has_rank_math() ) {
			add_filter( 'rank_math/json_ld', array( $this, 'inject_rank_math_schema' ), 99, 2 );
			return;
		}

		if ( $this->has_yoast() ) {
			add_filter( 'wpseo_json_ld_output', array( $this, 'inject_yoast_schema' ), 99, 1 );
			return;
		}

		add_action( 'wp_head', array( $this, 'render_fallback_schema' ), 99 );
	}

	/**
	 * Injects schema into Rank Math graph.
	 *
	 * @param array $data Existing graph data.
	 * @return array
	 */
	public function inject_rank_math_schema( $data ) {
		$graph = $this->build_graph_nodes();
		if ( empty( $graph ) ) {
			return $data;
		}

		foreach ( $graph as $key => $node ) {
			if ( $this->graph_contains_id( $data, $node['@id'] ) ) {
				continue;
			}
			$data[ $key ] = $node;
		}

		return $data;
	}

	/**
	 * Injects schema into Yoast JSON-LD output.
	 *
	 * @param array $graph Existing graph nodes.
	 * @return array
	 */
	public function inject_yoast_schema( $graph ) {
		if ( ! is_array( $graph ) ) {
			$graph = array();
		}

		$nodes = $this->build_graph_nodes();
		if ( empty( $nodes ) ) {
			return $graph;
		}

		foreach ( $nodes as $node ) {
			if ( $this->graph_contains_id( $graph, $node['@id'] ) ) {
				continue;
			}
			$graph[] = $node;
		}

		return $graph;
	}

	/**
	 * Renders JSON-LD when no SEO plugin graph is available.
	 *
	 * @return void
	 */
	public function render_fallback_schema() {
		$nodes = $this->build_graph_nodes();
		if ( empty( $nodes ) ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>';
	}

	/**
	 * Builds graph nodes for the current post when eligible.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function build_graph_nodes() {
		$post_id = $this->get_current_post_id();
		if ( empty( $post_id ) ) {
			return array();
		}

		if ( ! $this->is_adjustment_applied( $post_id ) ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return array();
		}

		$webpage_node = $this->build_webpage_node( $post );
		$org_node     = $this->build_publisher_node();

		return array(
			'ais_seo_squad_webpage'  => $webpage_node,
			'ais_seo_squad_publisher' => $org_node,
		);
	}

	/**
	 * Builds the WebPage node.
	 *
	 * @param WP_Post $post Current post.
	 * @return array<string,mixed>
	 */
	private function build_webpage_node( $post ) {
		$post_id      = (int) $post->ID;
		$permalink    = get_permalink( $post_id );
		$schema_id    = trailingslashit( $permalink ) . '#/schema/ai-webpage';
		$publisher_id = trailingslashit( home_url( '/' ) ) . '#/schema/organization';

		$description = (string) get_post_meta( $post_id, AIS_Data_Manager::META_LAST_APPLIED_DESCRIPTION, true );
		if ( '' === $description ) {
			$description = (string) $post->post_excerpt;
		}

		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $schema_id,
			'url'        => $permalink,
			'name'       => wp_strip_all_tags( get_the_title( $post_id ) ),
			'isPartOf'   => array(
				'@id' => trailingslashit( home_url( '/' ) ) . '#website',
			),
			'publisher'  => array(
				'@id' => $publisher_id,
			),
			'datePublished' => get_post_time( 'c', true, $post_id ),
			'dateModified'  => get_post_modified_time( 'c', true, $post_id ),
		);

		if ( '' !== $description ) {
			$node['description'] = wp_strip_all_tags( $description );
		}

		return $node;
	}

	/**
	 * Builds Organization/MedicalBusiness publisher node.
	 *
	 * @return array<string,mixed>
	 */
	private function build_publisher_node() {
		$publisher_type = apply_filters( 'ais_schema_publisher_type', 'Organization' );
		$publisher_type = in_array( $publisher_type, array( 'Organization', 'MedicalBusiness' ), true ) ? $publisher_type : 'Organization';

		$node = array(
			'@type' => $publisher_type,
			'@id'   => trailingslashit( home_url( '/' ) ) . '#/schema/organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $logo_url ) {
				$node['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_url,
				);
			}
		}

		return $node;
	}

	/**
	 * Returns current singular post id when available.
	 *
	 * @return int
	 */
	private function get_current_post_id() {
		if ( ! is_singular() ) {
			return 0;
		}

		$post_id = get_queried_object_id();

		return $post_id ? (int) $post_id : 0;
	}

	/**
	 * Checks if an adjustment has been applied for the post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_adjustment_applied( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, AIS_Data_Manager::META_ADJUSTMENT_APPLIED, true );
	}

	/**
	 * Checks whether a graph already contains a given @id.
	 *
	 * @param array  $graph Existing graph.
	 * @param string $id Node id.
	 * @return bool
	 */
	private function graph_contains_id( $graph, $id ) {
		if ( empty( $id ) || ! is_array( $graph ) ) {
			return false;
		}

		foreach ( $graph as $node ) {
			if ( is_array( $node ) && ! empty( $node['@id'] ) && (string) $node['@id'] === (string) $id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether Rank Math is available.
	 *
	 * @return bool
	 */
	private function has_rank_math() {
		return defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Returns whether Yoast is available.
	 *
	 * @return bool
	 */
	private function has_yoast() {
		return defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' );
	}
}
