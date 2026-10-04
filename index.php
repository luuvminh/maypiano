<?php
/**
 * Any other page or post.
 */
get_header();
echo '<main class="mp-plain">';
if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		the_title( '<h1>', '</h1>' );
		the_content();
	}
}
echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">Về trang chủ</a></p></main>';
get_footer();
