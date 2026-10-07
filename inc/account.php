<?php
/**
 * Learner account pages in the site's own look and in Vietnamese: sign in, forgot password,
 * set a new password. Learners never see the WordPress screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function maypiano_account_url( $screen, $args = array() ) {
	return add_query_arg( array( 'tk' => $screen ) + $args, home_url( '/' ) );
}

/** Where a learner signs in. */
function maypiano_login_url() {
	return maypiano_account_url( 'dang-nhap' );
}

/** The link that lets a learner choose a password. Good for 24 hours, like WordPress's own. */
function maypiano_password_url( $user ) {
	$key = get_password_reset_key( $user );
	return is_wp_error( $key ) ? '' : maypiano_account_url( 'dat-mat-khau', array( 'key' => rawurlencode( $key ), 'login' => rawurlencode( $user->user_login ) ) );
}

/** Links already sent, and WordPress's own "lost password" links, land on these pages instead. */
add_action( 'login_init', function () {
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	if ( in_array( $action, array( 'rp', 'resetpass' ), true ) && isset( $_GET['key'], $_GET['login'] ) ) {
		wp_safe_redirect( maypiano_account_url( 'dat-mat-khau', array(
			'key'   => rawurlencode( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ),
			'login' => rawurlencode( sanitize_text_field( wp_unslash( $_GET['login'] ) ) ),
		) ) );
		exit;
	}
	if ( in_array( $action, array( 'lostpassword', 'retrievepassword' ), true ) ) {
		wp_safe_redirect( maypiano_account_url( 'quen-mat-khau' ) );
		exit;
	}
} );
add_filter( 'lostpassword_url', function () {
	return maypiano_account_url( 'quen-mat-khau' );
}, 20 );

/** Where a learner signs out. The pass in the link makes it one press; without a good pass our own page asks first. */
function maypiano_logout_url() {
	return maypiano_account_url( 'dang-xuat', array( '_wpnonce' => wp_create_nonce( 'log-out' ) ) );
}
/* Every sign-out link on the site (the profile menu, the learner dashboard, the admin bar) leads to ours. */
add_filter( 'logout_url', function () {
	return maypiano_logout_url();
}, 20 );
/* WordPress's own "Do you really want to log out?" screen is never shown: an old link lands on our page instead. */
add_action( 'login_init', function () {
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( 'logout' === $action ) {
		wp_safe_redirect( maypiano_account_url( 'dang-xuat', isset( $_REQUEST['_wpnonce'] ) ? array( '_wpnonce' => sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ) ) : array() ) );
		exit;
	}
}, 1 );

/* WordPress's "You are now logged out" screen is never shown either: whoever lands there goes to the home page. */
add_action( 'login_init', function () {
	if ( 'GET' === $_SERVER['REQUEST_METHOD'] && isset( $_GET['loggedout'] ) && ! isset( $_GET['action'] ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}, 1 );
/* After signing out, always the home page — also when a plugin signs somebody out its own way. */
add_filter( 'logout_redirect', function () {
	return home_url( '/' );
}, 20 );
/*
 * The WordPress sign-in screen stays for the site's owner (it carries the WordPress.com sign-in), dressed in the May Piano look:
 * same background, fonts, logo and button colour. Learners sign in on our own page and never need this one.
 */
add_action( 'login_enqueue_scripts', function () {
	$logo = esc_url( get_template_directory_uri() . '/assets/img/logo.png' );
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Newsreader:opsz,wght@6..72,400;6..72,600&display=swap">' // phpcs:ignore WordPress.WP.EnqueuedResources
		. '<style>body.login{background:#E3D7D7;color:#2B1E24;font-family:Newsreader,Georgia,serif;font-size:18px}'
		. 'body.login #login h1 a,body.login .wp-login-logo a{background-image:url(' . $logo . ');background-size:contain;background-position:center;width:207px;height:120px}' // phpcs:ignore WordPress.Security.EscapeOutput
		. 'body.login form,body.login #loginform,body.login .jetpack-sso-form-display #loginform{background:#fff;border:2px solid #2B1E24;border-radius:14px;box-shadow:none}'
		. 'body.login .message,body.login .notice,body.login #login_error{border:2px solid #2B1E24;border-left-width:2px;border-radius:12px;box-shadow:none;background:#fff;color:#2B1E24}'
		. 'body.login label,body.login #nav,body.login #backtoblog,body.login h2,body.login p{font-family:Newsreader,Georgia,serif;color:#2B1E24}'
		. 'body.login input[type=text],body.login input[type=password],body.login input[type=email]{border:2px solid #2B1E24;border-radius:12px;min-height:52px;font-family:inherit}'
		. 'body.login .button-primary,body.login a.jetpack-sso.button,body.login .jetpack-sso.button{background:#C2456B!important;border-color:#C2456B!important;color:#fff!important;border-radius:999px!important;font-family:Newsreader,Georgia,serif;font-weight:600;font-size:18px;min-height:52px;line-height:1.3;padding:12px 22px;box-shadow:none!important;text-shadow:none}'
		. 'body.login .button:not(.button-primary):not(.jetpack-sso){border:2px solid #2B1E24;border-radius:999px;color:#2B1E24;background:transparent}'
		. 'body.login a,body.login #nav a,body.login #backtoblog a{color:#2B1E24}body.login a:hover,body.login #nav a:hover,body.login #backtoblog a:hover{color:#C2456B}'
		. 'body.login :is(a,button,input,select):focus{outline:3px solid #2B1E24;outline-offset:2px;box-shadow:none}</style>';
} );
add_filter( 'login_headerurl', function () {
	return home_url( '/' );
} );
add_filter( 'login_headertext', function () {
	return 'Mây Piano';
} );

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['tk'] ) || 'dang-xuat' !== sanitize_key( wp_unslash( $_GET['tk'] ) ) ) {
		return;
	}
	nocache_headers();
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	$pass = isset( $_REQUEST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
	if ( '' !== $pass && wp_verify_nonce( $pass, 'log-out' ) ) {
		wp_logout();
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	maypiano_account_page( 'Đăng xuất', '<p>Bạn muốn đăng xuất khỏi Mây Piano?</p>'
		. '<form method="post" action="' . esc_url( maypiano_account_url( 'dang-xuat' ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'log-out' ) ) . '">'
		. '<button type="submit">Đăng xuất</button></form>'
		. '<p><a href="' . esc_url( maypiano_learn_url() ) . '">Ở lại và vào học</a></p>' );
}, 9 );

add_action( 'template_redirect', function () {
	$screen = isset( $_GET['tk'] ) ? sanitize_key( wp_unslash( $_GET['tk'] ) ) : '';
	if ( ! in_array( $screen, array( 'dang-nhap', 'quen-mat-khau', 'dat-mat-khau' ), true ) ) {
		return;
	}
	nocache_headers();
	$post  = 'POST' === $_SERVER['REQUEST_METHOD'];
	$field = function ( $name ) {
		return isset( $_POST[ $name ] ) ? trim( (string) wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security
	};
	$error = '';

	if ( 'dat-mat-khau' === $screen ) {
		$key   = $post ? $field( 'key' ) : ( isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '' );
		$login = $post ? $field( 'login' ) : ( isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '' );
		$user  = '' !== $key && '' !== $login ? check_password_reset_key( $key, $login ) : new WP_Error( 'missing' );
		if ( is_wp_error( $user ) ) {
			maypiano_account_page( 'Đường dẫn đã hết hạn', '<p>Đường dẫn đặt mật khẩu chỉ dùng được một lần, trong 24 giờ. Bạn bấm nút dưới đây, Mây gửi cho bạn một đường dẫn mới.</p>'
				. '<p><a class="btn" href="' . esc_url( maypiano_account_url( 'quen-mat-khau' ) ) . '">Nhận đường dẫn mới</a></p>' );
		}
		if ( $post ) {
			$pass = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security
			if ( strlen( $pass ) < 8 ) {
				$error = 'Mật khẩu cần ít nhất 8 ký tự. Bạn thêm vài ký tự nữa nhé.';
			} else {
				reset_password( $user, $pass );
				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID, true );
				wp_safe_redirect( maypiano_learn_url() );
				exit;
			}
		}
		maypiano_account_page( 'Đặt mật khẩu', '<p>Bạn chọn một mật khẩu cho tài khoản <strong>' . esc_html( $user->user_email ) . '</strong>. Lần sau bạn dùng email này và mật khẩu này để vào học.</p>'
			. maypiano_account_error( $error )
			. '<form method="post"><input type="hidden" name="key" value="' . esc_attr( $key ) . '"><input type="hidden" name="login" value="' . esc_attr( $login ) . '">'
			. maypiano_account_pass( 'Mật khẩu mới', 'new-password' )
			. '<p class="hint">Ít nhất 8 ký tự. Bạn nên ghi lại ở chỗ dễ tìm.</p>'
			. '<button type="submit">Lưu mật khẩu và vào học</button></form>' );
	}

	if ( 'quen-mat-khau' === $screen ) {
		if ( $post ) {
			$email = sanitize_email( $field( 'email' ) );
			if ( ! is_email( $email ) ) {
				$error = 'Bạn xem lại email giúp Mây, hình như còn thiếu hoặc sai.';
			} elseif ( maypiano_throttled( 'pw', 6 ) ) {
				$error = 'Bạn đã thử nhiều lần. Bạn chờ một giờ rồi thử lại nhé.';
			} else {
				$user = get_user_by( 'email', $email );
				$url  = $user ? maypiano_password_url( $user ) : '';
				if ( $url ) {
					maypiano_mail( $user->user_email, 'Đặt lại mật khẩu Mây Piano', '<p>Chào ' . esc_html( $user->display_name ) . ',</p><p>Bạn bấm nút dưới đây để đặt mật khẩu mới:</p>'
						. maypiano_mail_button( $url, 'Đặt mật khẩu mới' )
						. '<p style="font-size:15px">Nút này dùng được trong 24 giờ. Nếu bạn không yêu cầu, bạn bỏ qua email này, mật khẩu cũ vẫn giữ nguyên.</p>' . maypiano_mail_support() );
				}
				// Same answer whether or not the email has an account, so nobody can probe who studies here.
				maypiano_account_page( 'Bạn kiểm tra email nhé', '<p>Nếu <strong>' . esc_html( $email ) . '</strong> đã có tài khoản, Mây vừa gửi tới đó một email có nút đặt mật khẩu mới.</p><p class="hint">Vài phút chưa thấy, bạn xem thử hộp Spam hoặc Quảng cáo.</p>'
					. '<p><a href="' . esc_url( maypiano_login_url() ) . '">Quay lại đăng nhập</a></p>' );
			}
		}
		maypiano_account_page( 'Quên mật khẩu', '<p>Bạn nhập email đã dùng khi đăng ký. Mây gửi cho bạn một đường dẫn để đặt mật khẩu mới.</p>'
			. maypiano_account_error( $error )
			. '<form method="post"><label for="mp-email">Email</label><input id="mp-email" type="email" name="email" autocomplete="email" inputmode="email" required>'
			. '<button type="submit">Gửi đường dẫn cho tôi</button></form>'
			. '<p><a href="' . esc_url( maypiano_login_url() ) . '">Quay lại đăng nhập</a></p>' );
	}

	// Sign in.
	$to = isset( $_REQUEST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ), '' ) : '';
	$to = $to ? $to : maypiano_learn_url();
	if ( is_user_logged_in() ) {
		wp_safe_redirect( $to );
		exit;
	}
	$email = '';
	if ( $post ) {
		$email = sanitize_text_field( $field( 'email' ) );
		$pass  = isset( $_POST['pass'] ) ? (string) wp_unslash( $_POST['pass'] ) : ''; // phpcs:ignore WordPress.Security
		if ( maypiano_throttled( 'login', 20 ) ) {
			$error = 'Bạn đã thử nhiều lần. Bạn chờ một giờ rồi thử lại, hoặc đặt mật khẩu mới.';
		} else {
			$user = wp_signon( array( 'user_login' => $email, 'user_password' => $pass, 'remember' => true ), is_ssl() );
			if ( ! is_wp_error( $user ) ) {
				wp_safe_redirect( $to );
				exit;
			}
			$error = 'Email hoặc mật khẩu chưa đúng. Bạn thử lại, hoặc bấm "Quên mật khẩu" bên dưới.';
		}
	}
	maypiano_account_page( 'Đăng nhập', '<p>Bạn đăng nhập để vào học.</p>'
		. maypiano_account_error( $error )
		. '<form method="post"><input type="hidden" name="redirect_to" value="' . esc_attr( $to ) . '">'
		. '<label for="mp-email">Email</label><input id="mp-email" type="text" name="email" value="' . esc_attr( $email ) . '" autocomplete="username" inputmode="email" autocapitalize="none" required>'
		. maypiano_account_pass( 'Mật khẩu', 'current-password' )
		. '<button type="submit">Đăng nhập</button></form>'
		. '<p><a href="' . esc_url( maypiano_account_url( 'quen-mat-khau' ) ) . '">Quên mật khẩu</a></p>'
		. '<p class="hint">Chưa có tài khoản? Tài khoản được tạo khi bạn <a href="' . esc_url( home_url( '/dang-ky/' ) ) . '">đăng ký khóa học</a>.</p>' );
} );

function maypiano_account_error( $message ) {
	return '' === $message ? '' : '<p class="err" role="alert">' . esc_html( $message ) . '</p>';
}

/** Password box with a show/hide switch, so nobody has to type it twice. */
function maypiano_account_pass( $label, $autocomplete ) {
	return '<label for="mp-pass">' . esc_html( $label ) . '</label><div class="pw"><input id="mp-pass" type="password" name="pass" autocomplete="' . esc_attr( $autocomplete ) . '" required>'
		. '<button type="button" class="eye" aria-pressed="false" onclick="var i=document.getElementById(\'mp-pass\'),s=i.type===\'password\';i.type=s?\'text\':\'password\';this.textContent=s?\'Ẩn\':\'Hiện\';this.setAttribute(\'aria-pressed\',s)">Hiện</button></div>';
}

function maypiano_account_page( $title, $body ) {
	echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' – Mây Piano</title>'; maypiano_icon_links(); echo ''
		. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
		. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Newsreader:opsz,wght@6..72,400;6..72,600&display=swap"><style>' // phpcs:ignore WordPress.WP.EnqueuedResources
		. '*{box-sizing:border-box}body{margin:0;background:#E3D7D7;color:#2B1E24;font-family:Newsreader,Georgia,serif;font-size:20px;line-height:1.55}'
		. 'main{max-width:520px;margin:0 auto;padding:28px 20px 56px}.logo{display:inline-block;margin-bottom:24px}.logo img{display:block;height:52px;width:auto}'
		. '.card{background:#fff;border:2px solid #2B1E24;border-radius:14px;padding:28px 24px}h1{font-family:"Playfair Display",Georgia,serif;font-weight:600;font-size:36px;line-height:1.12;margin:0 0 14px}'
		. 'p{margin:0 0 16px}a{color:#2B1E24}p>a:only-child{display:inline-block;padding:11px 0}p>a.btn:only-child{display:block;padding:14px 20px}label{display:block;font-weight:600;margin:18px 0 6px}'
		. 'input[type=text],input[type=email],input[type=password]{width:100%;min-height:56px;border:2px solid #2B1E24;border-radius:12px;padding:10px 14px;font:inherit;color:inherit;background:#fff}'
		. '.pw{position:relative}.pw input{padding-right:84px}.eye{position:absolute;right:6px;top:6px;height:44px;min-width:68px;border:0;border-radius:8px;background:#F6EFEF;color:#2B1E24;font:600 17px Newsreader,Georgia,serif;cursor:pointer}'
		. 'button[type=submit],.btn{display:block;width:100%;min-height:58px;margin-top:22px;border:0;border-radius:999px;background:#C2456B;color:#fff;font:600 20px Newsreader,Georgia,serif;padding:14px 20px;text-align:center;text-decoration:none;cursor:pointer}'
		. ':is(a,button,input):focus-visible{outline:3px solid #2B1E24;outline-offset:3px}form{margin:0 0 20px}.hint{font-size:17px}.err{background:#F6EFEF;border:2px solid #9A2B2B;border-radius:12px;padding:12px 14px;font-weight:600}'
		. '</style></head><body><main><a class="logo" href="' . esc_url( home_url( '/' ) ) . '"><img src="' . esc_url( get_template_directory_uri() . '/assets/img/logo.png' ) . '" alt="Mây Piano" width="413" height="240"></a><div class="card"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

/**
 * Who is signed in, for the profile menu in the top bar: name, email and the learner's own links. Null for a guest.
 * The home page asks for this after it loads (it is cached); the other pages print it straight away.
 */
function maypiano_me() {
	if ( ! is_user_logged_in() ) {
		return null;
	}
	$user  = wp_get_current_user();
	$name  = maypiano_qna_name( $user->ID );
	$parts = preg_split( '/\s+/u', $name );
	// Vietnamese names end with the given name: "Nguyễn Thị Lan" is called Lan.
	$short = 'Học viên' === $name ? 'Bạn' : (string) end( $parts );
	$dash  = function ( $page ) {
		return function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( $page ) : maypiano_learn_url();
	};
	// The learner's own picture: the one uploaded in Hồ sơ, else their Gravatar. A learner with neither keeps the round initial.
	$photo_id = (int) get_user_meta( $user->ID, '_tutor_profile_photo', true );
	$photo    = $photo_id ? (string) wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
	if ( '' === $photo ) {
		$photo = (string) get_avatar_url( $user->ID, array( 'size' => 96, 'default' => '404' ) );
	}
	return array(
		'name'    => $name,
		'photo'   => esc_url_raw( $photo ),
		'short'   => $short,
		'initial' => mb_strtoupper( mb_substr( 'Học viên' === $name ? (string) $user->user_email : $short, 0, 1 ) ),
		'email'   => (string) $user->user_email,
		'learn'   => esc_url_raw( maypiano_learn_url() ),
		'links'   => array(
			array( 'label' => 'Khóa học của mình', 'url' => esc_url_raw( $dash( 'enrolled-courses' ) ) ),
			array( 'label' => 'Hỏi đáp của mình', 'url' => esc_url_raw( $dash( 'question-answer' ) ) ),
			array( 'label' => 'Hồ sơ và mật khẩu', 'url' => esc_url_raw( $dash( 'settings' ) ) ),
		),
		'out'     => esc_url_raw( maypiano_logout_url() ),
	);
}

/** The profile menu as printed on every page but the home page (which draws its own from the same facts). */
function maypiano_me_menu() {
	$me = maypiano_me();
	if ( ! $me ) {
		return '';
	}
	$av   = function ( $extra ) use ( $me ) {
		return '<span class="mp-me-av' . $extra . '" aria-hidden="true">' . esc_html( $me['initial'] ) . ( '' !== $me['photo'] ? '<span class="mp-me-pic" style="background-image:url(' . esc_url( $me['photo'] ) . ')"></span>' : '' ) . '</span>';
	};
	$html = '<details class="mp-me"><summary aria-label="' . esc_attr( 'Tài khoản của ' . $me['name'] ) . '">' . $av( '' )
		. '<svg class="mp-me-chev" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 4.5L6 8l3.5-3.5"></path></svg></summary>'
		. '<div class="mp-me-menu"><div class="mp-me-head">' . $av( ' mp-me-big' ) . '<div class="mp-me-who"><strong>' . esc_html( $me['name'] ) . '</strong><span>' . esc_html( $me['email'] ) . '</span></div></div>';
	foreach ( $me['links'] as $link ) {
		$html .= '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
	}
	return $html . '<a class="mp-me-out" href="' . esc_url( $me['out'] ) . '">Đăng xuất</a></div></details>'
		. '<script>(function(){var d=document.querySelector(".mp-me");if(!d)return;document.addEventListener("pointerdown",function(e){if(d.open&&!d.contains(e.target))d.open=false});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&d.open){d.open=false;d.querySelector("summary").focus()}})})();</script>';
}
