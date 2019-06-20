<?php

namespace SearchWP_Related;

use WP_Post;

/**
 * Class Meta_Box
 *
 * @package SearchWP_Related
 * @since 0.0.1
 */
class Meta_Box {

	private $related;
	private $nonce_key              = 'searchwp_related_nonce';
	private $nonce_action           = 'searchwp_related_keywords';
	private $excluded_post_types    = array( 'attachment' );
	private $existing_keywords      = '';

	/**
	 * Meta_Box constructor.
	 *
	 * @internal param SearchWP_Related $searchwp_related
	 *
	 * @internal param WP_Post $post
	 */
	function __construct() {
		$this->related = new \SearchWP_Related();
	}

	/**
	 * Initialize
	 */
	function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 3 );
		add_action( 'admin_footer', array( $this, 'preview_javascript' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 999, 1 );
		add_action( 'wp_ajax_searchwp_related_preview', array( $this, 'get_samples_as_json' ) );
	}

	/**
	 * Callback to save submitted keywords
	 *
	 * @param $post_id
	 *
	 * @return mixed
	 */
	function save_post( $post_id, $post, $update ) {

		if ( ! isset( $_POST['post_type'] ) || ! isset( $_POST['searchwp_related_keywords'] ) ) {
			return $post_id;
		}

		if ( ! isset( $_POST[ $this->nonce_key ] ) || ! wp_verify_nonce( $_POST[ $this->nonce_key ], $this->nonce_action ) ) {
			return $post_id;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}

		if ( in_array( $_POST['post_type'], $this->excluded_post_types, true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		$keywords = trim( $_POST['searchwp_related_keywords'] );

		if ( empty( $keywords ) && ( $post->post_date_gmt === '0000-00-00 00:00:00' || $post->post_status == 'future' ) ) {
			// This is the first save, so let's grab fallback keywords
			$keywords = apply_filters( 'searchwp_related_default_keywords', $this->related->clean_string( $post->post_title ) );
		}

		if ( empty( $keywords ) ) {
			// Intentionally left empty
			update_post_meta( $post_id, $this->related->meta_key . '_skip', true );
		} else {
			delete_post_meta( $post_id, $this->related->meta_key . '_skip' );
		}

		update_post_meta( $post_id, $this->related->meta_key, sanitize_text_field( $keywords ) );

		return $post_id;

	}

	/**
	 * Register meta box
	 *
	 * @param string    $post_type  The current post type
	 * @param WP_Post   $post       The current post object
	 */
	function register_meta_box( $post_type, $post ) {

		$this->excluded_post_types = (array) apply_filters( 'searchwp_related_excluded_post_types', $this->excluded_post_types );

		$this->related->set_post( $post );

		// Let developers omit meta box from post type(s)
		if ( in_array( $post_type, $this->excluded_post_types, true ) ) {
			return;
		}

		// Let developers omit meta box based on single post
		$exclude_post = apply_filters( 'searchwp_related_exclude_post', false, $post );
		if ( ! empty( $exclude_post ) ) {
			return;
		}

		// Ok. Add meta box.
		add_meta_box(
			'searchwp-related',
			apply_filters( 'searchwp_related_meta_box_title', __( 'SearchWP Related Content', 'searchwp_related' ) ),
			array( $this, 'render_meta_box' ),
			$post_type,
			apply_filters( 'searchwp_related_meta_box_context', 'normal' ),
			apply_filters( 'searchwp_related_meta_box_priority', 'high' )
		);
	}

	/**
	 * Get associative array of sample results for keywords
	 *
	 * @param string $existing_keywords
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	function get_samples( $existing_keywords = '', $post_id = 0 ) {
		$samples = array();

		foreach ( SWP()->settings['engines'] as $engine => $engine_settings ) {

			$post_data = $this->related->get( array(
				'engine'        => $engine,
				's'             => $existing_keywords,
				'post__not_in'  => array( $post_id )
			), $post_id );

			if ( ! empty( $post_data ) ) {
				foreach ( $post_data as $key => $val ) {
					$val = absint( $val );
					$post_data[ $key ] = array(
						'ID'            => $val,
						'post_title'    => get_the_title( $val ),
						'permalink'     => get_permalink( $val ),
						'post_type'     => get_post_type( $val ),
					);
				}
			}

			$samples[] = array(
				'engine' => array(
					'name'  => $engine,
					'label' => isset( $engine_settings['searchwp_engine_label'] ) ? esc_html( $engine_settings['searchwp_engine_label'] ) : esc_html__( 'Default', 'searchwp_related' ),
				),
				'samples' => empty( $post_data ) ? array() : $post_data,
			);
		}

		return $samples;
	}

	/**
	 * Callback for AJAX action to retrieve samples
	 */
	function get_samples_as_json() {

		if ( ! isset( $_POST['nonce'] ) || ! isset( $_POST['post_id'] ) || ! wp_verify_nonce( $_POST['nonce'], 'searchwp_related_preview_' . absint( $_POST['post_id'] ) ) ) {
			die();
		}

		if ( ! isset( $_POST['terms'] ) ) {
			die();
		}

		$terms = sanitize_text_field( $_POST['terms'] );
		$post_id = absint( $_POST['post_id'] );

		echo wp_json_encode( $this->get_samples( $terms, $post_id ) );

		die();
	}

	/**
	 * Render meta box
	 */
	function render_meta_box() {

		$this->existing_keywords = get_post_meta( $this->related->post->ID, $this->related->meta_key, true );

		if ( empty( $this->existing_keywords ) ) {
			$this->existing_keywords = $this->related->maybe_get_fallback_keywords( $this->related->post->ID );
		}

		wp_nonce_field( $this->nonce_action, $this->nonce_key );

		?>
		<div class="searchwp-related">
			<p>
				<label for="searchwp_related_keywords"><?php esc_html_e( 'Keyword(s) to use when finding related content', 'searchwp_related' ); ?></label>
				<input class="widefat" type="text" name="searchwp_related_keywords" id="searchwp_related_keywords" value="<?php echo esc_attr( $this->existing_keywords ); ?>" size="30" />
			</p>
			<div class="searchwp-related-previews-wrapper">
				<div class="searchwp-related-previews-wrapper-heading">
					<h3><?php esc_html_e( 'Results Sample', 'searchwp_related' ); ?></h3>
					<p class="description">The <a href="#">template loader</a> determines how results appear on your site (this is just a sampling)</p>
				</div>
				<div class="searchwp-related-previews">
					<?php $skipped = get_post_meta( $this->related->post->ID, $this->related->meta_key . '_skip', true ); ?>
					<?php if ( ! empty( $skipped ) ) : ?>
						<p class="description"><?php echo esc_html( $this->get_message( 'skipped' ) ); ?></p>
					<?php else : ?>
						<?php $samples = $this->get_samples( $this->existing_keywords, $this->related->post->ID ); ?>
						<?php foreach ( $samples as $sample ) : ?>
							<div class="searchwp-related-preview">
								<?php if ( count( $samples ) > 1 ) : ?>
									<h4><?php echo esc_html( $sample['engine']['label'] ); ?></h4>
								<?php endif; ?>
								<?php $this->render_related( $sample['samples'] ); ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<div class="spinner"></div>
				</div>
			</div>
		</div>

		<style type="text/css">
			.searchwp-related-previews-wrapper-heading,
			.searchwp-related-previews {
				display: flex;
				width: 100%;
			}

			.searchwp-related-previews-wrapper-heading {
				align-items: baseline;
			}

			.searchwp-related-previews-wrapper-heading h3 {
				padding-right: 0.5em;
				margin-top: 0.6em;
				margin-bottom: 0.6em;
			}

			.searchwp-related-previews {
				background-color: #f9f9f9;
				border: 1px solid #ddd;
				position: relative;
			}

			.searchwp-related-previews > p.description {
				padding: 1em;
				margin: 0;
			}

			.searchwp-related-previews.searchwp-related-previews-loading p.description,
			.searchwp-related-previews.searchwp-related-previews-loading .searchwp-related-preview {
				opacity: 0.5;
			}

			.searchwp-related-previews .spinner {
				opacity: 1;
				display: none;
				position: absolute;
				top: 50%;
				left: 50%;
				margin: -10px 0 0 -10px;
				visibility: visible;
			}

			.searchwp-related-previews.searchwp-related-previews-loading .spinner {
				display: block;
			}

			.searchwp-related-preview {
				flex: 1;
				padding: 0.8em 1em 1em;
			}

			.searchwp-related-preview p {
				margin: 0.2em 0 0;
			}

			.searchwp-related-preview ol {
				margin-top: 0.5em;
				margin-bottom: 0;
			}

			.searchwp-related-preview h4 {
				margin-top: 0;
				margin-bottom: 0;
			}

			.searchwp-related label {
				display: block;
				line-height: 1.6;
				margin-bottom: 0.5em;
			}

			.searchwp-related span {
				display: block;
				line-height: 1.4;
				margin-top: 0.4em;
			}
		</style>
		<?php
	}

	/**
	 * Render the related content
	 *
	 * @param array $existing_related
	 * @param bool $template
	 */
	function render_related( $existing_related = array(), $template = false ) {
		if ( ! empty( $template ) ) {
			echo "<% if (searchwp_related_engines>1) { %>\n";
			echo '<h4><%- searchwp_related.engine.label %></h4>';
			echo "\n<% } %>\n";
		}
		if ( empty( $existing_related ) || ! empty( $template ) ) {
			if ( ! empty( $template ) ) {
				echo "<% if (searchwp_related.samples.length<1) { %>\n";
			}
			echo '<p class="description">' . esc_html__( 'No results found', 'searchwp_related' ) . '</p>';
			if ( ! empty( $template ) ) {
				echo "\n<% } %>\n";
			}
		}
		if ( ! empty( $existing_related ) || ! empty( $template ) ) {
			if ( ! empty( $template ) ) {
				echo "<% if (searchwp_related.samples.length>0) { %>";
			}
			echo '<ol>';
			if ( ! empty( $template ) ) {
				echo "\n<% _.each(searchwp_related.samples, function(sample) { %>\n";
			}
			foreach ( $existing_related as $related ) { ?>
				<?php $related_id = absint( $related['ID'] ); ?>
				<li>
					<a href="<?php if ( empty( $template ) ) {
						echo esc_url( get_permalink( $related_id ) );
					} else {
						echo "<%- sample.permalink %>";
					} ?>"><?php if ( empty( $template ) ) {
							echo esc_html( get_the_title( $related_id ) );
						} else {
							echo "<%- sample.post_title %>";
						} ?></a>
					(<?php if ( empty( $template ) ) {
						echo esc_html( get_post_type( $related_id ) );
					} else {
						echo "<%- sample.post_type %>";
					} ?>)
				</li>
			<?php }
			if ( ! empty( $template ) ) {
				echo "\n<% }); %>\n";
			}
			echo '</ol>';
			if ( ! empty( $template ) ) {
				echo "\n<% } %>\n";
			}
		}
	}

	/**
	 * Output JavaScript in the footer
	 */
	function preview_javascript() { ?>
		<script type="text/template" id="tmpl-searchwp-related">
			<div class="searchwp-related-preview">
				<?php $this->render_related( array( 0 ), true ); ?>
			</div>
		</script>
		<script type="text/javascript" >
			jQuery(document).ready(function($) {

				var timer;
				var last = '<?php echo esc_js( $this->existing_keywords ); ?>';
				var $input = $('#searchwp_related_keywords');
				var $container = $('.searchwp-related-previews');
				var data = {
					'action': 'searchwp_related_preview',
					'post_id': <?php echo absint( $this->related->post->ID ); ?>,
					'nonce': '<?php echo esc_js( wp_create_nonce( 'searchwp_related_preview_' . absint( $this->related->post->ID ) ) ); ?>'
				};
				var template = _.template($('#tmpl-searchwp-related').html());

				$input.on("keyup paste", function() {
					clearTimeout(timer);
					timer = setTimeout(function() {
						if ( last !== $input.val() ) {
							last = $input.val();

							data.terms = $input.val();

							$container.addClass('searchwp-related-previews-loading');

							if(data.terms){
								jQuery.post(ajaxurl, data, function (response) {

									var samples = $.parseJSON(response);

									$container.empty();

									$.each((samples), function( index, value ) {
										$container.append(template({
											searchwp_related: value,
											searchwp_related_engines: samples.length
										}));
									});

									$container.removeClass('searchwp-related-previews-loading');
								});
							} else {
								$container.html('<p class="description"><?php echo esc_html( $this->get_message( 'skipped' ) ); ?></p>');
								$container.removeClass('searchwp-related-previews-loading');
							}

						}
					}, 500);
				});
			});
		</script>
	<?php }

	/**
	 * Output various messages
	 *
	 * @param $message
	 *
	 * @return string
	 */
	function get_message( $message ) {

		$markup = '';

		switch( $message ) {
			case 'skipped':
				$post_type_object = get_post_type_object( $this->related->post->post_type );
				$markup = sprintf(
					// Translators: the placeholder is the post type label singular name
					__( 'Keywords removed; Related content will be skipped for this %s', 'searchwp_related' ),
					$post_type_object->labels->singular_name );
				break;
		}

		return $markup;
	}

	/**
	 * Callback for on-page assets
	 *
	 * @param $hook
	 */
	function assets( $hook ) {
		global $post;

		if ( in_array( $post->post_type, $this->excluded_post_types, true ) ) {
			return;
		}

		// Let developers omit meta box based on single post
		$exclude_post = apply_filters( 'searchwp_related_exclude_post', false, $post );
		if ( ! empty( $exclude_post ) ) {
			return;
		}

		if ( 'edit.php' !== $hook && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'backbone' );
	}
}
