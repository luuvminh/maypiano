<?php
/**
 * May Piano theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAYPIANO_VERSION', '1.0.0' );

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
	wp_enqueue_script( 'maypiano-runtime', get_theme_file_uri( 'assets/js/runtime.js' ), array(), MAYPIANO_VERSION, true );
	wp_localize_script( 'maypiano-runtime', 'MayPianoCfg', array(
		'rest'  => esc_url_raw( rest_url( 'maypiano/v1/' ) ),
		'nonce' => wp_create_nonce( 'wp_rest' ),
	) );
} );

/** Settings the owner fills in under Settings > May Piano. */
function maypiano_fields() {
	return array(
		'maypiano_account'    => array( 'Số tài khoản Techcombank', 'Hiện ở bước thanh toán.' ),
		'maypiano_qr'         => array( 'Link ảnh mã QR chuyển khoản', 'Địa chỉ ảnh trong Thư viện. Để trống thì không hiện mã QR.' ),
		'maypiano_usd'        => array( 'Giá USD cho PayPal', 'Ví dụ: 100 USD mỗi khóa.' ),
		'maypiano_processing' => array( 'Thời gian kích hoạt tài khoản học', 'Ví dụ: 24 giờ.' ),
		'maypiano_notify'     => array( 'Email nhận thông báo đơn mới', 'Để trống thì dùng email quản trị của site.' ),
	);
}

add_action( 'admin_init', function () {
	foreach ( maypiano_fields() as $key => $field ) {
		register_setting( 'maypiano', $key, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}
} );

add_action( 'admin_menu', function () {
	add_options_page( 'May Piano', 'May Piano', 'manage_options', 'maypiano', function () {
		echo '<div class="wrap"><h1>May Piano</h1><form method="post" action="options.php">';
		settings_fields( 'maypiano' );
		echo '<table class="form-table" role="presentation">';
		foreach ( maypiano_fields() as $key => $field ) {
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input class="regular-text" type="text" id="%1$s" name="%1$s" value="%3$s"><p class="description">%4$s</p></td></tr>',
				esc_attr( $key ),
				esc_html( $field[0] ),
				esc_attr( get_option( $key, '' ) ),
				esc_html( $field[1] )
			);
		}
		echo '</table>';
		submit_button();
		echo '</form></div>';
	} );
} );

/** Prints one page template with links, images and owner settings filled in. */
function maypiano_render( $name ) {
	$file = get_theme_file_path( 'templates/' . $name . '.html' );
	if ( ! is_readable( $file ) ) {
		return;
	}
	$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$account    = get_option( 'maypiano_account', '' );
	$qr         = get_option( 'maypiano_qr', '' );
	$usd        = get_option( 'maypiano_usd', '' );
	$processing = get_option( 'maypiano_processing', '' );

	$qr_box = '<div style="flex: none; width: 150px; height: 150px; background: #FFFFFF; border: 2px solid #2B1E24; border-radius: 10px; display: flex; align-items: center; justify-content: center; text-align: center; font-size: 16px; padding: 10px; box-sizing: border-box">[Mã QR chuyển khoản]</div>';
	$qr_html = $qr ? '<img src="' . esc_url( $qr ) . '" alt="Mã QR chuyển khoản" style="flex: none; width: 150px; height: 150px; object-fit: contain; background: #FFFFFF; border: 2px solid #2B1E24; border-radius: 10px">' : '';

	$replace = array(
		'%%THEME%%'          => esc_url( get_template_directory_uri() ),
		'%%HOME%%'           => esc_url( home_url( '/' ) ),
		'%%SIGNUP%%'         => esc_url( home_url( '/dang-ky/' ) ),
		$qr_box              => $qr_html,
		'[SỐ TÀI KHOẢN]'     => $account ? esc_html( $account ) : 'Mây gửi qua email sau khi bạn đặt đơn',
		'[GIÁ USD]'          => $usd ? esc_html( $usd ) : 'Mây báo giá USD qua email',
		'[THỜI GIAN XỬ LÝ]'  => $processing ? esc_html( $processing ) : 'thời gian sớm nhất',
	);
	echo strtr( $html, $replace ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/** Orders and email sign-ups are kept as private entries the owner sees in the dashboard. */
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
		'label'     => 'Đơn đăng ký',
		'menu_icon' => 'dashicons-cart',
	) ) );
	register_post_type( 'mp_sub', array_merge( $common, array(
		'label'     => 'Email nhận bài học',
		'menu_icon' => 'dashicons-email',
	) ) );
} );

function maypiano_notify_address() {
	$to = get_option( 'maypiano_notify', '' );
	return is_email( $to ) ? $to : get_option( 'admin_email' );
}

/** Allows a handful of requests per visitor per hour. */
function maypiano_throttled( $bucket ) {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'mp_' . $bucket . '_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 10 ) {
		return true;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return false;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'maypiano/v1', '/order', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			if ( maypiano_throttled( 'order' ) ) {
				return new WP_Error( 'too_many', 'Too many requests', array( 'status' => 429 ) );
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
			if ( ! is_email( $email ) || '' === $name ) {
				return new WP_Error( 'invalid', 'Missing name or email', array( 'status' => 400 ) );
			}
			$code   = sanitize_text_field( (string) $req->get_param( 'code' ) );
			$method = 'paypal' === $req->get_param( 'method' ) ? 'PayPal' : 'Chuyển khoản';
			$total  = absint( $req->get_param( 'total' ) );
			$items  = array_slice( array_map( 'sanitize_text_field', (array) $req->get_param( 'items' ) ), 0, 10 );

			$body = "Mã đơn: $code\nHọ tên: $name\nEmail: $email\nCách thanh toán: $method\nKhóa học: " . implode( ', ', $items ) . "\nTổng: " . number_format( $total, 0, ',', '.' ) . 'đ';
			$id   = wp_insert_post( array(
				'post_type'    => 'mp_order',
				'post_status'  => 'private',
				'post_title'   => $code . ' · ' . $name,
				'post_content' => $body,
			) );
			if ( is_wp_error( $id ) || ! $id ) {
				return new WP_Error( 'save_failed', 'Could not save', array( 'status' => 500 ) );
			}
			wp_mail( maypiano_notify_address(), 'May Piano: đơn mới ' . $code, $body );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'maypiano/v1', '/subscribe', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			if ( maypiano_throttled( 'sub' ) ) {
				return new WP_Error( 'too_many', 'Too many requests', array( 'status' => 429 ) );
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'invalid', 'Invalid email', array( 'status' => 400 ) );
			}
			$existing = get_posts( array( 'post_type' => 'mp_sub', 'post_status' => 'private', 'title' => $email, 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( ! $existing ) {
				wp_insert_post( array( 'post_type' => 'mp_sub', 'post_status' => 'private', 'post_title' => $email ) );
			}
			return array( 'ok' => true );
		},
	) );
} );
