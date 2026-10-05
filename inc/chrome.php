<?php
/**
 * One look for every page that is not the home page or the sign-up page:
 * shop, course, learner dashboard, account, plain pages. Same header, footer, fonts and colours as the home page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** True on pages that need the shared header, footer and app styles. The two designed pages bring their own. */
function maypiano_chrome_needed() {
	return ! is_front_page() && ! is_page( 'dang-ky' );
}

add_action( 'after_setup_theme', function () {
	add_theme_support( 'woocommerce' );
} );

add_filter( 'body_class', function ( $classes ) {
	if ( maypiano_chrome_needed() ) {
		$classes[] = 'mp-app';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! maypiano_chrome_needed() ) {
		return;
	}
	wp_enqueue_style( 'maypiano-fonts', 'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,400;1,6..72,500&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
	wp_enqueue_style( 'maypiano-app', get_theme_file_uri( 'assets/css/app.css' ), array( 'maypiano', 'maypiano-fonts' ), MAYPIANO_VERSION );
}, 99 );

/** Catalog key of the course or product being viewed, or ''. */
function maypiano_key_for_post( $post_id ) {
	foreach ( array_keys( maypiano_catalog() ) as $key ) {
		$product = maypiano_product_for( $key );
		if ( $product && (int) $product->get_id() === (int) $post_id ) {
			return $key;
		}
		if ( maypiano_course_for( $key, $product ? $product->get_id() : 0 ) === (int) $post_id ) {
			return $key;
		}
	}
	return '';
}

function maypiano_signup_url( $key = '' ) {
	$url = home_url( '/dang-ky/' );
	return '' === $key ? $url : add_query_arg( 'khoa', rawurlencode( $key ), $url );
}

/**
 * There is one way to buy: the sign-up page. The shop, product, cart and checkout pages lead there.
 * The pay-for-order and order-received pages stay, because card payments use them.
 */
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_woocommerce' ) || is_admin() ) {
		return;
	}
	$target = '';
	if ( is_product() ) {
		$target = maypiano_signup_url( maypiano_key_for_post( get_queried_object_id() ) );
	} elseif ( is_shop() || is_product_taxonomy() || is_cart() ) {
		$target = maypiano_signup_url();
	} elseif ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) ) {
		$target = maypiano_signup_url();
	}
	if ( '' !== $target ) {
		wp_safe_redirect( $target, 302 );
		exit;
	}
} );

/**
 * One way in: learners sign in on the May Piano sign-in page, and an account is made when a course is bought.
 * The plugins' own sign-in, registration and course-list pages lead to ours, so nobody meets a second look.
 */
add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$target = '';
	if ( is_post_type_archive( 'courses' ) ) {
		$target = home_url( '/#chon-khoa' );
	} elseif ( ! is_user_logged_in() ) {
		$tutor    = function_exists( 'tutor_utils' ) ? tutor_utils() : null;
		$register = array_filter( array( 'student-registration', 'instructor-registration', $tutor ? (int) $tutor->get_option( 'student_register_page' ) : 0, $tutor ? (int) $tutor->get_option( 'instructor_register_page' ) : 0 ) );
		$signin   = array_filter( array( 'dashboard', $tutor ? (int) $tutor->get_option( 'tutor_dashboard_page_id' ) : 0 ) );
		if ( is_page( $register ) ) {
			$target = maypiano_signup_url();
		} elseif ( is_page( $signin ) || ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			$target = maypiano_login_url();
		}
	}
	if ( '' !== $target ) {
		nocache_headers();
		wp_safe_redirect( $target, 302 );
		exit;
	}
}, 1 );

/** Nobody signs themselves up: accounts come from orders (wc_create_new_customer does not look at this setting). */
add_filter( 'pre_option_users_can_register', '__return_zero' );

/** On a course page the buy button goes to the sign-up page with that course picked. */
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'courses' ) ) {
		return;
	}
	$url = maypiano_signup_url( maypiano_key_for_post( get_queried_object_id() ) );
	?>
<script>
document.addEventListener('click', function (e) {
	var el = e.target.closest('.tutor-add-to-cart-button, .tutor-native-add-to-cart, [name="add-to-cart"], .single_add_to_cart_button, a.add_to_cart_button, a[href*="add-to-cart="]');
	if (!el) { return; }
	e.preventDefault();
	e.stopPropagation();
	window.location.href = <?php echo wp_json_encode( esc_url_raw( $url ) ); ?>;
}, true);
</script>
	<?php
} );

function maypiano_site_header() {
	if ( ! maypiano_chrome_needed() ) {
		return;
	}
	$home = esc_url( home_url( '/' ) );
	?>
<header class="mp-top">
<nav class="mp-nav" aria-label="Chính">
<a class="mp-logo" href="<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput ?>"><img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo.png' ); ?>" alt="Mây Piano" width="413" height="240"></a>
<div class="mp-links">
<a href="<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput ?>#chon-khoa">Chọn khóa</a>
<a href="<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput ?>#lo-trinh">Lộ trình</a>
<a href="<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput ?>#bai-hat">Bài hát</a>
<a href="<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput ?>#cong-dong">Cộng đồng</a>
	<?php if ( is_user_logged_in() ) : ?>
<a class="mp-pill" href="<?php echo esc_url( maypiano_learn_url() ); ?>">Khóa học của mình</a>
	<?php else : ?>
<a class="mp-enter" href="<?php echo esc_url( maypiano_login_url() ); ?>">Vào học</a>
<a class="mp-pill" href="<?php echo esc_url( maypiano_signup_url() ); ?>">Đăng ký học</a>
	<?php endif; ?>
</div>
</nav>
</header>
<div class="mp-page">
	<?php
}

function maypiano_site_footer() {
	if ( ! maypiano_chrome_needed() ) {
		return;
	}
	?>
</div>
<footer class="mp-foot">
<div>© <?php echo esc_html( gmdate( 'Y' ) ); ?> Mây Piano</div>
<div class="mp-foot-links"><a href="<?php echo esc_url( home_url( '/cau-hoi-thuong-gap/' ) ); ?>">Câu hỏi thường gặp</a><a href="https://www.facebook.com/maypianist">Fanpage</a><a href="https://www.facebook.com/groups/558515685499528/">Nhóm Facebook</a><a href="https://www.youtube.com/channel/UCHTx5Zck4uICJoIzeuDdq_g">YouTube</a><a href="https://www.tiktok.com/@maypiano">TikTok</a></div>
</footer>
	<?php
}

/** The May Piano icon for browser tabs, bookmarks, phone home screens and search results. */
function maypiano_icon_links() {
	$dir = esc_url( get_template_directory_uri() . '/assets/img/' );
	// phpcs:disable WordPress.Security.EscapeOutput
	echo '<link rel="icon" href="' . $dir . 'favicon.ico" sizes="any">'
		. '<link rel="icon" type="image/png" sizes="32x32" href="' . $dir . 'icon-32.png">'
		. '<link rel="icon" type="image/png" sizes="192x192" href="' . $dir . 'icon-192.png">'
		. '<link rel="icon" type="image/png" sizes="512x512" href="' . $dir . 'icon-512.png">'
		. '<link rel="apple-touch-icon" href="' . $dir . 'apple-touch-icon.png">'
		. '<meta name="theme-color" content="#E3D7D7">' . "\n";
	// phpcs:enable
}
add_action( 'wp_head', 'maypiano_icon_links', 1 );
add_action( 'admin_head', 'maypiano_icon_links', 1 );
add_action( 'login_head', 'maypiano_icon_links', 1 );
/* The theme's icon replaces any WordPress one, so there is only ever one. */
add_filter( 'get_site_icon_url', '__return_empty_string' );
remove_action( 'wp_head', 'wp_site_icon', 99 );
/* Browsers and search engines that ask for /favicon.ico directly get it too. */
add_action( 'do_faviconico', function () {
	wp_safe_redirect( get_template_directory_uri() . '/assets/img/favicon.ico', 301 );
	exit;
}, 1 );
