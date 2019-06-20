<?php
/**
 * SearchWP Engine: default
 * Maximum Results: 3
 */

/**
 * This is the default SearchWP Related results template. If you would like to
 * customize this template, DO NOT EDIT THIS FILE. Instead, create a folder
 * named 'searchwp-related' in your active theme, and copy this file inside.
 *
 * You may create multiple results templates based on the post being viewed,
 * simply append the post type name to the file name like so:
 *
 *      ~/your-theme-folder/searchwp-related/related-page.php
 *
 * That template file will be used whenever you view a Page on your site, while
 * the default (related.php) template would be used for everything else.
 *
 * You may customize the SearchWP engine used to find results by editing
 * the "SearchWP Engine" name at the top of this file.
 *
 * You may customize the number of related entries returned by that engine
 * by editing the "Maximum Results" at the top of this file.
 */

// DO NOT remove global $post; unless you're being intentional
global $post; ?>

<?php
/**
 * $searchwp_related is an array of posts, defined within the SearchWP Related plugin
 */
if ( ! empty( $searchwp_related ) ) : ?>
    <div class="searchwp-related">
        <h4><?php esc_html_e( 'Related Content', 'searchwp_related' ); ?></h4>
        <ol>
            <?php
            // Loop through each related entry and set up the main $post
            foreach ( $searchwp_related as $post ) : setup_postdata( $post ); ?>
                <li>
                    <a href="<?php echo esc_url( get_permalink() ); ?>">
                        <?php the_post_thumbnail(); ?>
                        <span><?php the_title(); ?></span>
                    </a>
                </li>
            <?php endforeach;

            // You MUST reset the $post data once you're done looping through results
            wp_reset_postdata(); ?>
        </ol>
    </div>

    <?php /* These styles should be moved into your theme and removed from this template */ ?>
    <style type="text/css">
        .searchwp-related > ol {
            list-style: none;
            padding: 0;
            display: flex;
            align-items: stretch;
            margin: 0 0 0 -1em;
        }

        .searchwp-related > ol > li {
            flex: 1;
            padding: 0 0 0 1em;
            display: flex;
            align-items: stretch;
        }

        .searchwp-related > ol > li > a {
            display: block;
            width: 100%;
            text-decoration: none;
            background-color: #f7f7f7;
            border: 1px solid #e7e7e7;
            border-radius: 2px;
        }

        .searchwp-related > ol > li > a > span {
            display: block;
            padding: 1em;
        }

        .searchwp-related > ol > li > a > img {
            display: block;
            max-width: 100%;
            height: auto !important;
        }

        .searchwp-related > ol > li > a:hover {
            border: 1px solid #e7e7e7;
        }
    </style>
<?php endif;