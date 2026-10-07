<?php
/**
 * Sheet nhạc: the page at /sheet-nhac/. The list of sheets is data/sheet-nhac.json.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Makes the page once, so the address works as soon as the theme is deployed. */
add_action( 'init', function () {
	if ( get_option( 'maypiano_sheet_page' ) ) {
		return;
	}
	if ( ! get_page_by_path( 'sheet-nhac' ) ) {
		$id = wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Sheet nhạc',
			'post_name'   => 'sheet-nhac',
		) );
		if ( ! $id || is_wp_error( $id ) ) {
			return;
		}
	}
	update_option( 'maypiano_sheet_page', 1 );
}, 20 );

/**
 * The sheets to show, in file order. Each one: title, and optionally note, level and url.
 * A sheet without a title is left out; a url that is not a web address is dropped.
 */
function maypiano_sheets() {
	$file = get_theme_file_path( 'data/sheet-nhac.json' );
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$out  = array();
	foreach ( (array) ( $data['sheets'] ?? array() ) as $row ) {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' === $title ) {
			continue;
		}
		$out[] = array(
			'title' => $title,
			'note'  => trim( (string) ( $row['note'] ?? '' ) ),
			'level' => trim( (string) ( $row['level'] ?? '' ) ),
			'url'   => esc_url_raw( (string) ( $row['url'] ?? '' ), array( 'https' ) ),
		);
	}
	return $out;
}

add_action( 'wp_head', function () {
	if ( is_page( 'sheet-nhac' ) ) {
		echo '<meta name="description" content="' . esc_attr( 'Sheet nhạc piano do Mây soạn, để bạn in ra hoặc mở trên máy và tập theo.' ) . '">' . "\n";
	}
}, 2 );
