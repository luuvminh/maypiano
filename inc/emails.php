<?php
/**
 * Emails of the sign-up flow, written the way Mây talks to a learner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function maypiano_notify_address() {
	$to = get_option( 'maypiano_notify', '' );
	return is_email( $to ) ? $to : get_option( 'admin_email' );
}

/** Sends one HTML email in the site's look. $body is trusted HTML built by the callers below. */
function maypiano_mail( $to, $subject, $body ) {
	return wp_mail( $to, $subject, maypiano_mail_wrap( $body ), array( 'Content-Type: text/html; charset=UTF-8' ) );
}

function maypiano_mail_wrap( $body ) {
	return '<div style="background:#F6EFEF;padding:24px 12px;font-family:Georgia,\'Times New Roman\',serif;color:#2B1E24;font-size:17px;line-height:1.6">'
		. '<div style="max-width:560px;margin:0 auto;background:#FFFFFF;border:2px solid #2B1E24;border-radius:14px;padding:28px">'
		. '<div style="font-size:24px;font-weight:bold;margin-bottom:16px">Mây Piano</div>'
		. $body
		. '</div></div>';
}

function maypiano_mail_button( $url, $label ) {
	return '<p style="margin:22px 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#C2456B;color:#FFFFFF;text-decoration:none;font-weight:bold;padding:13px 26px;border-radius:999px">' . esc_html( $label ) . '</a></p>';
}

/** The order's lines and total as a small table. */
function maypiano_mail_lines( $order ) {
	$currency = $order->get_currency();
	$quote    = (bool) $order->get_meta( '_mp_needs_quote' );
	$rows     = '';
	foreach ( $order->get_items() as $item ) {
		$rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #E3D7D7">' . esc_html( $item->get_name() ) . '</td><td style="padding:6px 0;border-bottom:1px solid #E3D7D7;text-align:right;white-space:nowrap">' . ( $quote ? '' : esc_html( maypiano_money( $item->get_total(), $currency ) ) ) . '</td></tr>';
	}
	$total = $quote ? 'Mây báo sau' : maypiano_money( $order->get_total(), $currency );
	return '<table style="width:100%;border-collapse:collapse;margin:14px 0">' . $rows
		. '<tr><td style="padding:10px 0;font-weight:bold">Tổng cộng</td><td style="padding:10px 0;text-align:right;font-weight:bold;font-size:20px;white-space:nowrap">' . esc_html( $total ) . '</td></tr></table>';
}

function maypiano_mail_support() {
	$support = maypiano_setting( 'maypiano_support' );
	return '<p style="margin:18px 0 0;font-size:15px">Cần Mây giúp, bạn ' . ( '' !== $support ? 'liên hệ ' . esc_html( $support ) . ' hoặc ' : '' ) . 'trả lời email này nhé.</p>';
}

/** To the customer, right after the order is placed: how to pay. */
function maypiano_mail_order_received( $order ) {
	$currency = $order->get_currency();
	$method   = $order->get_meta( '_mp_method' );
	$quote    = (bool) $order->get_meta( '_mp_needs_quote' );
	$amount   = maypiano_money( $order->get_total(), $currency );
	$code     = maypiano_order_code( $order );
	$name     = $order->get_billing_first_name();

	$body = '<p>Chào ' . esc_html( $name ) . ',</p><p>Mây đã nhận đơn <strong>' . esc_html( $code ) . '</strong> của bạn.</p>' . maypiano_mail_lines( $order );
	if ( $quote ) {
		$body .= '<p>Giá cho vùng của bạn Mây sẽ gửi trong email kế tiếp, kèm cách trả tiền. Bạn chưa cần làm gì thêm.</p>';
	} elseif ( 'bank' === $method ) {
		$account = maypiano_setting( 'maypiano_account' );
		$body   .= '<p><strong>Bạn chuyển khoản theo thông tin này:</strong></p><table style="border-collapse:collapse;margin:0 0 6px">'
			. '<tr><td style="padding:3px 14px 3px 0">Ngân hàng</td><td><strong>Techcombank</strong></td></tr>'
			. '<tr><td style="padding:3px 14px 3px 0">Chủ tài khoản</td><td><strong>' . esc_html( maypiano_setting( 'maypiano_account_name' ) ) . '</strong></td></tr>'
			. ( '' !== $account ? '<tr><td style="padding:3px 14px 3px 0">Số tài khoản</td><td><strong>' . esc_html( $account ) . '</strong></td></tr>' : '' )
			. '<tr><td style="padding:3px 14px 3px 0">Số tiền</td><td><strong>' . esc_html( $amount ) . '</strong></td></tr>'
			. '<tr><td style="padding:3px 14px 3px 0">Nội dung</td><td><strong>' . esc_html( maypiano_memo( $order ) ) . '</strong></td></tr></table>'
			. '<p>Ghi đúng nội dung <strong>' . esc_html( maypiano_memo( $order ) ) . '</strong> để Mây nhận ra đơn của bạn. Mã QR có sẵn số tiền nằm ở trang đơn hàng:</p>'
			. maypiano_mail_button( maypiano_order_url( $order ), 'Mở trang đơn hàng và mã QR' );
	} else {
		$link  = maypiano_paypal_url( (float) $order->get_total(), $currency );
		$to    = maypiano_setting( 'maypiano_paypal' );
		$body .= '<p><strong>Bạn trả ' . esc_html( $amount ) . ' qua PayPal</strong>, phần ghi chú điền mã <strong>' . esc_html( maypiano_memo( $order ) ) . '</strong>.</p>';
		if ( $link ) {
			$body .= maypiano_mail_button( $link, 'Trả ' . $amount . ' qua PayPal' );
		} elseif ( is_email( $to ) ) {
			$body .= '<p>Tài khoản PayPal nhận tiền: <strong>' . esc_html( $to ) . '</strong></p>';
		} else {
			$body .= '<p>Mây sẽ gửi link PayPal cho bạn trong email kế tiếp.</p>';
		}
		$body .= '<p>Bạn xem lại đơn bất cứ lúc nào ở đây: <a href="' . esc_url( maypiano_order_url( $order ) ) . '" style="color:#8A3350">trang đơn hàng</a>.</p>';
	}
	if ( ! $quote ) {
		$body .= '<p>Nhận được tiền, Mây mở khóa học và gửi email cho bạn trong ' . esc_html( maypiano_setting( 'maypiano_processing' ) ) . '.</p>';
	}
	$body .= maypiano_mail_support();
	return maypiano_mail( $order->get_billing_email(), 'Mây đã nhận đơn ' . $code . ' của bạn', $body );
}

/** To the customer, once payment is confirmed: how to get in. */
function maypiano_mail_access( $order, $user_id, $opened, $missing ) {
	$code = maypiano_order_code( $order );
	$user = $user_id ? get_user_by( 'id', $user_id ) : null;
	$body = '<p>Chào ' . esc_html( $order->get_billing_first_name() ) . ',</p><p>Mây đã nhận được tiền cho đơn <strong>' . esc_html( $code ) . '</strong>. Cảm ơn bạn nhiều.</p>' . maypiano_mail_lines( $order );
	if ( $user && $opened ) {
		$body .= '<p>Khóa học đã mở cho tài khoản <strong>' . esc_html( $user->user_email ) . '</strong>.</p>';
		if ( $order->get_meta( '_mp_new_account' ) ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$body .= '<p>Đây là lần đầu bạn học ở Mây Piano, nên bạn đặt mật khẩu trước rồi vào học:</p>'
					. maypiano_mail_button( network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' ), 'Đặt mật khẩu và vào học' )
					. '<p style="font-size:15px">Nút này dùng được trong 24 giờ. Quá hạn, bạn bấm "Quên mật khẩu" ở <a href="' . esc_url( wp_login_url( maypiano_learn_url() ) ) . '" style="color:#8A3350">trang đăng nhập</a> để nhận nút mới.</p>';
			}
		} else {
			$body .= '<p>Bạn đăng nhập bằng mật khẩu đang dùng:</p>' . maypiano_mail_button( wp_login_url( maypiano_learn_url() ), 'Đăng nhập và vào học' );
		}
	}
	if ( $missing ) {
		$body .= '<p>' . ( $opened ? 'Riêng ' . esc_html( implode( ', ', $missing ) ) . ' Mây đang mở bằng tay' : 'Mây đang mở khóa học cho bạn bằng tay' ) . ' và sẽ gửi email ngay khi xong, trong ' . esc_html( maypiano_setting( 'maypiano_processing' ) ) . '.</p>';
	}
	$body .= maypiano_mail_support();
	return maypiano_mail( $order->get_billing_email(), $opened ? 'Khóa học của bạn đã mở, đơn ' . $code : 'Mây đã nhận tiền đơn ' . $code, $body );
}

/** One line per fact the owner needs to act on an order. */
function maypiano_mail_owner_facts( $order ) {
	$labels = array( 'bank' => 'Chuyển khoản', 'paypal' => 'PayPal', 'card' => 'Thẻ' );
	$method = $order->get_meta( '_mp_method' );
	$facts  = array(
		'Mã đơn'          => maypiano_order_code( $order ) . ( $order->get_meta( '_mp_test' ) ? ' (CHẠY THỬ)' : '' ),
		'Họ tên'          => $order->get_billing_first_name(),
		'Email'           => $order->get_billing_email(),
		'Điện thoại'      => $order->get_billing_phone(),
		'Vùng'            => $order->get_meta( '_mp_region' ),
		'Cách trả'        => isset( $labels[ $method ] ) ? $labels[ $method ] : $method,
		'Nội dung chuyển' => maypiano_memo( $order ),
	);
	$html = '<table style="border-collapse:collapse">';
	foreach ( $facts as $label => $value ) {
		if ( '' !== (string) $value ) {
			$html .= '<tr><td style="padding:3px 14px 3px 0">' . esc_html( $label ) . '</td><td><strong>' . esc_html( $value ) . '</strong></td></tr>';
		}
	}
	return $html . '</table>' . maypiano_mail_lines( $order );
}

function maypiano_mail_owner_subject( $order, $lead ) {
	$amount = $order->get_meta( '_mp_needs_quote' ) ? 'cần báo giá' : maypiano_money( $order->get_total(), $order->get_currency() );
	return '[May Piano] ' . $lead . ' ' . maypiano_order_code( $order ) . ' · ' . $order->get_billing_first_name() . ' · ' . $amount;
}

function maypiano_mail_owner_new_order( $order ) {
	$body = '<p>Có đơn mới.</p>' . maypiano_mail_owner_facts( $order );
	if ( $order->get_meta( '_mp_needs_quote' ) ) {
		$body .= '<p><strong>Chưa có giá cho vùng của khách.</strong> Điền giá vào đơn, rồi gửi khách số tiền và cách trả.</p>';
	} else {
		$body .= '<p>Khi tiền về tài khoản, mở đơn và đổi trạng thái sang <strong>Completed</strong>. Site sẽ tự tạo tài khoản học, mở khóa học và gửi email cho khách.</p>';
	}
	$body .= maypiano_mail_button( $order->get_edit_order_url(), 'Mở đơn trong WooCommerce' );
	return maypiano_mail( maypiano_notify_address(), maypiano_mail_owner_subject( $order, 'Đơn mới' ), $body );
}

function maypiano_mail_owner_reported( $order ) {
	$body = '<p><strong>Khách báo đã trả tiền.</strong> Kiểm tra tài khoản, thấy đúng số tiền và nội dung <strong>' . esc_html( maypiano_memo( $order ) ) . '</strong> thì đổi đơn sang <strong>Completed</strong>.</p>'
		. maypiano_mail_owner_facts( $order )
		. maypiano_mail_button( $order->get_edit_order_url(), 'Mở đơn để xác nhận' );
	return maypiano_mail( maypiano_notify_address(), maypiano_mail_owner_subject( $order, 'Khách báo đã trả' ), $body );
}

function maypiano_mail_owner_access_problem( $order, $missing ) {
	$body = '<p><strong>Đơn đã trả tiền nhưng site chưa mở được khóa học:</strong> ' . esc_html( implode( ', ', $missing ) ) . '.</p>'
		. '<p>Việc cần làm: vào Tutor LMS, ghi danh khách vào khóa học bằng tay, rồi báo khách. Sau đó vào Cài đặt &gt; May Piano kiểm tra cột "Khóa Tutor LMS" để lần sau site tự mở.</p>'
		. maypiano_mail_owner_facts( $order )
		. maypiano_mail_button( $order->get_edit_order_url(), 'Mở đơn trong WooCommerce' );
	return maypiano_mail( maypiano_notify_address(), maypiano_mail_owner_subject( $order, 'CẦN GHI DANH TAY' ), $body );
}

/** To the customer, when money goes back. */
function maypiano_mail_refunded( $order, $amount, $full ) {
	$code = maypiano_order_code( $order );
	$sum  = maypiano_money( $amount, $order->get_currency() );
	$body = '<p>Chào ' . esc_html( $order->get_billing_first_name() ) . ',</p>'
		. '<p>Mây đã hoàn <strong>' . esc_html( $sum ) . '</strong> cho đơn <strong>' . esc_html( $code ) . '</strong>, theo đúng cách bạn đã trả.</p>'
		. maypiano_mail_lines( $order );
	if ( $full ) {
		$body .= '<p>Khóa học của đơn này đã đóng. Khi nào bạn muốn học lại, Mây luôn chào đón bạn.</p>';
	}
	$body .= '<p>Vài ngày nữa mà bạn chưa thấy tiền về, bạn báo để Mây kiểm tra nhé.</p>' . maypiano_mail_support();
	return maypiano_mail( $order->get_billing_email(), 'Mây đã hoàn tiền đơn ' . $code, $body );
}

add_action( 'woocommerce_order_refunded', function ( $order_id, $refund_id ) {
	$order  = wc_get_order( $order_id );
	$refund = wc_get_order( $refund_id );
	if ( ! maypiano_is_ours( $order ) || ! $refund || $order->get_meta( '_mp_test' ) ) {
		return;
	}
	maypiano_mail_refunded( $order, (float) $refund->get_amount(), (float) $order->get_remaining_refund_amount() <= 0 );
}, 20, 2 );

foreach ( array( 'customer_refunded_order', 'customer_partially_refunded_order' ) as $maypiano_email_id ) {
	add_filter( 'woocommerce_email_enabled_' . $maypiano_email_id, function ( $enabled, $order = null ) {
		return maypiano_is_ours( $order ) ? false : $enabled;
	}, 20, 2 );
}
unset( $maypiano_email_id );

/** "Forgot password", in Mây's voice, whichever form the learner used. */
function maypiano_mail_password_body( $user, $key ) {
	$url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
	return '<p>Chào ' . esc_html( $user->display_name ) . ',</p>'
		. '<p>Có người vừa xin đặt lại mật khẩu cho tài khoản <strong>' . esc_html( $user->user_email ) . '</strong> ở Mây Piano. Nếu đó là bạn, bạn bấm nút dưới đây:</p>'
		. maypiano_mail_button( $url, 'Đặt mật khẩu mới' )
		. '<p style="font-size:15px">Nút này dùng được trong 24 giờ. Nếu không phải bạn, bạn cứ bỏ qua email này, mật khẩu cũ vẫn giữ nguyên.</p>'
		. maypiano_mail_support();
}

add_filter( 'retrieve_password_notification_email', function ( $mail, $key, $user_login, $user ) {
	if ( ! $user instanceof WP_User ) {
		return $mail;
	}
	$mail['subject'] = 'Đặt lại mật khẩu Mây Piano của bạn';
	$mail['message'] = maypiano_mail_wrap( maypiano_mail_password_body( $user, $key ) );
	$mail['headers'] = array( 'Content-Type: text/html; charset=UTF-8' );
	return $mail;
}, 20, 4 );

add_filter( 'woocommerce_email_enabled_customer_reset_password', '__return_false', 20 );
add_action( 'woocommerce_reset_password_notification', function ( $user_login = '', $key = '' ) {
	$user = get_user_by( 'login', $user_login );
	if ( $user && $key ) {
		maypiano_mail( $user->user_email, 'Đặt lại mật khẩu Mây Piano của bạn', maypiano_mail_password_body( $user, $key ) );
	}
}, 20, 2 );
