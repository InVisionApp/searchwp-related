<?php

/**
 * Class SearchWP_Related
 */
class SearchWP_Related {

	public $settings;

	private $meta_box;
	private $template;
	private $related;
	private $engine;

	public $meta_key;
	public $post;

	/**
	 * SearchWP_Related constructor.
	 */
	function __construct() {
		$this->meta_key = 'searchwp_related';

		require_once SEARCHWP_RELATED_PLUGIN_DIR . '/vendor/autoload.php';
		require_once SEARCHWP_RELATED_PLUGIN_DIR . '/admin/settings.php';

		$this->settings = new SearchWP_Related_Settings();
		$this->settings->init();
	}

	/**
	 * Setter for engine name
	 *
	 * @param string $engine Valid engine name
	 */
	public function set_engine( $engine = 'default' ) {
		$this->engine = SWP()->is_valid_engine( $engine ) ? $engine : 'default';
	}

	/**
	 * Initialize
	 */
	function init() {

		add_action( 'wp', array( $this, 'set_post' ) );

		add_action( 'admin_init', array( $this, 'admin_init' ) );

		if ( ! is_admin() ) {
			$this->template = new SearchWP_Related\Template();
			$this->template->init();
		}
	}

	/**
	 * Check for edit screen in WP Admin
	 *
	 * @param null $new_edit
	 *
	 * @return bool
	 */
	function is_edit_page( $new_edit = null ) {
		global $pagenow;

		if ( ! is_admin() ) {
			return false;
		}

		if ( 'edit' === $new_edit ) {
			return in_array( $pagenow, array( 'post.php' ), true );
		}  elseif ( 'new' === $new_edit ) {
			return in_array( $pagenow, array( 'post-new.php' ), true );
		} else {
			return in_array( $pagenow, array( 'post.php', 'post-new.php' ), true );
		}
	}

	/**
	 * Callback for admin_init; implements meta box
	 */
	function admin_init() {

		if ( ! $this->is_edit_page() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
 			return;
		}

		$this->meta_box = new SearchWP_Related\Meta_Box();
		$this->meta_box->init();
	}

	/**
	 * Setter for the post object to work with
	 *
	 * @param null $post
	 */
	public function set_post( $post = null ) {
		if ( empty( $post ) || ! $post instanceof WP_Post ) {
			$post = get_queried_object();
		}

		if ( $post instanceof WP_Post ) {
			$this->post = $post;
		}
	}

	/**
	 * Determine a fallback/default set of keywords if none are found
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	function maybe_get_fallback_keywords( $post_id = 0 ) {
		$keywords = '';

		// The keywords may have been intentionally removed
		$skipped = get_post_meta( $post_id, $this->meta_key . '_skip', true );

		if ( ! empty( $skipped ) ) {
			return $keywords;
		}

		// If there are no terms, it likely means this plugin was installed
		// after content already existed, so let's assume the title works
		if ( apply_filters( 'searchwp_related_use_fallback_keywords', true, $post_id ) ) {

			$keywords = apply_filters( 'searchwp_related_default_keywords', get_the_title( $post_id ) );

			if ( 'auto-draft' === get_post_status( $post_id ) ) {
				$keywords = '';
			}

			$keywords = $this->clean_string( $keywords );

			if ( ! empty( $keywords ) ) {
				update_post_meta( $post_id, $this->meta_key, sanitize_text_field( $keywords ) );
			}
		}

		return $keywords;
	}

	/**
	 * Generate a somewhat-tokenized string of keywords
	 *
	 * @param $keywords
	 *
	 * @return array|string
	 */
	public function clean_string( $keywords ) {
		// Titles often have HTML entities
		$keywords = html_entity_decode( $keywords );

		// Pre-process the string (e.g. remove common words)
		$keywords = SWP()->sanitize_terms( $keywords );

		// There's a max search terms so let's use that
		$max_terms = apply_filters( 'searchwp_max_search_terms', 6 );

		if ( count( $keywords ) > $max_terms ) {
			$keywords = array_splice( $keywords, 0, $max_terms );
		}

		$keywords = implode( ' ', $keywords );

		return $keywords;
	}

	/**
	 * Retrieve the related content for a specific post
	 *
	 * @param array $args
	 *
	 * @param int $post_id
	 *
	 * @return array The related posts
	 */
	public function get( $args = array(), $post_id = 0 ) {

		if ( empty( $post_id ) ) {
			$post_id = get_the_ID();
		}

		$post_id = absint( $post_id );

		$defaults = array(
			'engine'            => $this->engine,   // Engine to use
			's'                 => '',              // Terms to search
			'fields'            => 'ids',           // Return IDs only
			'posts_per_page'    => 3,               // How many results to return
			'log'               => false,           // Log the search?
			'post__in'          => array(),         // Limit results pool?
			'post__not_in'      => array()          // Exclude posts?
		);

		// Process our arguments
		$args = wp_parse_args( $args, $defaults );

		// If there are no terms, it likely means this plugin was installed
		// after content already existed, so let's retrieve fallback keywords
		if ( empty( $args['s'] ) ) {
			$args['s'] = $this->maybe_get_fallback_keywords( $post_id );
		}

		// Format post__in
		if ( ! is_array( $args['post__in'] ) ) {
			$args['post__in'] = array( $args['post__in'] );
		}

		// Format post__not_in
		if ( ! is_array( $args['post__not_in'] ) ) {
			$args['post__not_in'] = array( $args['post__not_in'] );
		}

		// We always want to force exclude the current post
		$args['post__not_in'][] = $post_id;
		$args['post__not_in'][] = get_queried_object_id();
		$args['post__not_in'] = array_unique( $args['post__not_in'] );

		// Prevent the search from being logged
		if ( empty( $args['log'] ) ) {
			add_filter( 'searchwp_log_search', 'searchwp_related_disable_hook' );
		}

		do_action( 'searchwp_related_pre_search', $args );

		// Find the related content
		$related = new SWP_Query( apply_filters( 'searchwp_related_query_args', $args ) );

		$this->related = $related->posts;

		do_action( 'searchwp_related_post_search', $args );

		// Undo previous hooks for this search only
		if ( empty( $args['log'] ) ) {
			remove_filter( 'searchwp_log_search', 'searchwp_related_disable_hook' );
		}

		return $this->related;
	}
}
