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
	echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . ' – Mây Piano</title>'
		. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
		. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Newsreader:opsz,wght@6..72,400;6..72,600&display=swap"><style>' // phpcs:ignore WordPress.WP.EnqueuedResources
		. '*{box-sizing:border-box}body{margin:0;background:#E3D7D7;color:#2B1E24;font-family:Newsreader,Georgia,serif;font-size:20px;line-height:1.55}'
		. 'main{max-width:520px;margin:0 auto;padding:28px 20px 56px}.logo{font-family:"Playfair Display",Georgia,serif;font-weight:700;font-size:28px;color:#2B1E24;text-decoration:none;display:inline-block;margin-bottom:24px}'
		. '.card{background:#fff;border:2px solid #2B1E24;border-radius:14px;padding:28px 24px}h1{font-family:"Playfair Display",Georgia,serif;font-weight:600;font-size:36px;line-height:1.12;margin:0 0 14px}'
		. 'p{margin:0 0 16px}a{color:#2B1E24}label{display:block;font-weight:600;margin:18px 0 6px}'
		. 'input[type=text],input[type=email],input[type=password]{width:100%;min-height:56px;border:2px solid #2B1E24;border-radius:12px;padding:10px 14px;font:inherit;color:inherit;background:#fff}'
		. '.pw{position:relative}.pw input{padding-right:84px}.eye{position:absolute;right:6px;top:6px;height:44px;min-width:68px;border:0;border-radius:8px;background:#F6EFEF;color:#2B1E24;font:600 17px Newsreader,Georgia,serif;cursor:pointer}'
		. 'button[type=submit],.btn{display:block;width:100%;min-height:58px;margin-top:22px;border:0;border-radius:999px;background:#C2456B;color:#fff;font:600 20px Newsreader,Georgia,serif;padding:14px 20px;text-align:center;text-decoration:none;cursor:pointer}'
		. ':is(a,button,input):focus-visible{outline:3px solid #2B1E24;outline-offset:3px}form{margin:0 0 20px}.hint{font-size:17px}.err{background:#F6EFEF;border:2px solid #9A2B2B;border-radius:12px;padding:12px 14px;font-weight:600}'
		. '</style></head><body><main><a class="logo" href="' . esc_url( home_url( '/' ) ) . '">Mây Piano</a><div class="card"><h1>' . esc_html( $title ) . '</h1>' . $body . '</div></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
