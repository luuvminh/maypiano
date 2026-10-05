<?php
/**
 * May Piano theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAYPIANO_VERSION', '1.3.2' );

require get_theme_file_path( 'inc/catalog.php' );
require get_theme_file_path( 'inc/settings.php' );
require get_theme_file_path( 'inc/emails.php' );
require get_theme_file_path( 'inc/orders.php' );
require get_theme_file_path( 'inc/approve.php' );
require get_theme_file_path( 'inc/chrome.php' );
require get_theme_file_path( 'inc/i18n.php' );
require get_theme_file_path( 'inc/fx.php' );
require get_theme_file_path( 'inc/mailpoet.php' );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'script', 'style' ) );
} );

/** Creates the sign-up page the first time the theme is switched on. */
add_action( 'after_switch_theme', function () {
	if ( ! get_page_by_path( 'dang-ky' ) ) {
		wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Đăng ký khóa học',
			'post_name'   => 'dang-ky',
		) );
	}
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'maypiano', get_stylesheet_uri(), array(), MAYPIANO_VERSION );
	$needs = array();
	if ( is_page( 'dang-ky' ) ) {
		wp_enqueue_script( 'maypiano-qrcode', get_theme_file_uri( 'assets/js/qrcode.js' ), array(), MAYPIANO_VERSION, true );
		wp_enqueue_script( 'maypiano-vietqr', get_theme_file_uri( 'assets/js/vietqr.js' ), array( 'maypiano-qrcode' ), MAYPIANO_VERSION, true );
		$needs[] = 'maypiano-vietqr';
	}
	wp_enqueue_script( 'maypiano-runtime', get_theme_file_uri( 'assets/js/runtime.js' ), $needs, MAYPIANO_VERSION, true );
	// No nonce here on purpose: pages are cached, and a stale nonce would make every request fail.
	wp_localize_script( 'maypiano-runtime', 'MayPianoCfg', array(
		'rest' => esc_url_raw( rest_url( 'maypiano/v1/' ) ),
		'ajax' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
	) );
} );

/** Prints one page template with its links and images filled in. */
function maypiano_render( $name ) {
	$file = get_theme_file_path( 'templates/' . $name . '.html' );
	if ( ! is_readable( $file ) ) {
		return;
	}
	$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	echo strtr( $html, array( // phpcs:ignore WordPress.Security.EscapeOutput
		'%%THEME%%'  => esc_url( get_template_directory_uri() ),
		'%%HOME%%'   => esc_url( home_url( '/' ) ),
		'%%SIGNUP%%' => esc_url( home_url( '/dang-ky/' ) ),
	) );
}

/** Email sign-ups, and sign-up requests from before orders moved into WooCommerce, are kept as private entries. */
add_action( 'init', function () {
	$common = array(
		'public'              => false,
		'show_ui'             => true,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'supports'            => array( 'title', 'editor' ),
		'capability_type'     => 'page',
	);
	register_post_type( 'mp_order', array_merge( $common, array(
		'label'     => 'Đơn đăng ký cũ',
		'menu_icon' => 'dashicons-archive',
	) ) );
	register_post_type( 'mp_sub', array_merge( $common, array(
		'label'     => 'Email nhận bài học',
		'menu_icon' => 'dashicons-email',
	) ) );
} );
