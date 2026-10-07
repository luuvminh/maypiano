<?php
/**
 * May Piano theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAYPIANO_VERSION', '1.11.3' );

require get_theme_file_path( 'inc/catalog.php' );
require get_theme_file_path( 'inc/settings.php' );
require get_theme_file_path( 'inc/emails.php' );
require get_theme_file_path( 'inc/orders.php' );
require get_theme_file_path( 'inc/approve.php' );
require get_theme_file_path( 'inc/account.php' );
require get_theme_file_path( 'inc/chrome.php' );
require get_theme_file_path( 'inc/i18n.php' );
require get_theme_file_path( 'inc/fx.php' );
require get_theme_file_path( 'inc/mailpoet.php' );
require get_theme_file_path( 'inc/videos.php' );
require get_theme_file_path( 'inc/curriculum.php' );
require get_theme_file_path( 'inc/bunny.php' );
require get_theme_file_path( 'inc/course-page.php' );
require get_theme_file_path( 'inc/teacher.php' );
require get_theme_file_path( 'inc/enrol-by-hand.php' );
require get_theme_file_path( 'inc/teacher-words.php' );
require get_theme_file_path( 'inc/qna.php' );
require get_theme_file_path( 'inc/sheets.php' );
require get_theme_file_path( 'inc/text-size.php' );

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
	$cfg = array(
		'rest' => esc_url_raw( rest_url( 'maypiano/v1/' ) ),
		'ajax' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
	);
	if ( is_front_page() ) {
		// What the page shows first. It then asks the site again, so a cached page still ends up with the newest three.
		$cfg['videos'] = maypiano_videos();
	}
	wp_localize_script( 'maypiano-runtime', 'MayPianoCfg', $cfg );
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
		'%%LOGIN%%'  => esc_url( maypiano_login_url() ), // Signed-in learners are sent straight on to their courses.
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

/** What search engines and shared links say about the two designed pages. */
add_action( 'wp_head', function () {
	$text = '';
	if ( is_front_page() ) {
		$text = 'Học piano online cùng Mây, từ nốt nhạc đầu tiên đến tự soạn hợp âm. Năm khóa học qua video, 284 bài, có sheet nhạc đi kèm.';
	} elseif ( is_page( 'dang-ky' ) ) {
		$text = 'Đăng ký khóa học piano online của Mây Piano. Bạn chọn khóa, trả bằng chuyển khoản hoặc PayPal, rồi nhận tài khoản học qua email.';
	}
	if ( '' !== $text ) {
		echo '<meta name="description" content="' . esc_attr( $text ) . '">' . "\n";
	}
}, 2 );

/**
 * The home page and the sign-up page run on the theme's own script and styles.
 * Visitors there do not download the shop, course and gallery plugins' files. Site statistics stay.
 */
function maypiano_slim_assets() {
	if ( maypiano_chrome_needed() || is_admin_bar_showing() ) {
		return;
	}
	foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
		foreach ( (array) $deps->queue as $handle ) {
			if ( 0 !== strpos( $handle, 'maypiano' ) && false === strpos( $handle, 'stats' ) ) {
				$deps->dequeue( $handle );
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'maypiano_slim_assets', 9999 );
add_action( 'wp_footer', 'maypiano_slim_assets', 1 );
add_action( 'wp', function () {
	if ( ! maypiano_chrome_needed() && ! is_admin() ) {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
	}
} );

/** A visitor who is not signed in needs no pass for the site's requests: answer plainly instead of with an error. */
add_action( 'wp_ajax_nopriv_rest-nonce', function () {
	nocache_headers();
	status_header( 200 );
	exit;
} );
