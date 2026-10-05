<?php
/**
 * Emails of the sign-up flow, written the way Mây talks to a learner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function maypiano_notify_address() {
	$to = array_filter( array_map( 'trim', explode( ',', (string) get_option( 'maypiano_notify', '' ) ) ), 'is_email' );
	return $to ? array_values( $to ) : get_option( 'admin_email' );
}

/** Sends one HTML email in the site's look. $body is trusted HTML built by the callers below. */
function maypiano_mail( $to, $subject, $body ) {
	$html = '<div style="background:#F6EFEF;padding:24px 12px;font-family:Georgia,\'Times New Roman\',serif;color:#2B1E24;font-size:17px;line-height:1.6">'
		. '<div style="max-width:560px;margin:0 auto;background:#FFFFFF;border:2px solid #2B1E24;border-radius:14px;padding:28px">'
		. '<div style="font-size:24px;font-weight:bold;margin-bottom:16px">Mây Piano</div>'
		. $body
		. '</div></div>';
	return wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
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
			$url = maypiano_password_url( $user );
			if ( $url ) {
				$body .= '<p>Đây là lần đầu bạn học ở Mây Piano, nên bạn đặt mật khẩu trước rồi vào học:</p>'
					. maypiano_mail_button( $url, 'Đặt mật khẩu và vào học' )
					. '<p style="font-size:15px">Nút này dùng được trong 24 giờ. Quá hạn, bạn bấm "Quên mật khẩu" ở <a href="' . esc_url( maypiano_login_url() ) . '" style="color:#8A3350">trang đăng nhập</a> để nhận nút mới.</p>';
			}
		} else {
			$body .= '<p>Bạn đăng nhập bằng mật khẩu đang dùng:</p>' . maypiano_mail_button( maypiano_login_url(), 'Đăng nhập và vào học' );
		}
	}
	if ( $missing ) {
		$body .= '<p>' . ( $opened ? 'Riêng ' . esc_html( implode( ', ', $missing ) ) : 'Khóa học của bạn' ) . ' sẽ được kích hoạt trong vòng ' . esc_html( maypiano_setting( 'maypiano_processing' ) ) . '. Mây sẽ gửi email báo ngay khi bạn có thể vào học.</p>';
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
		$body .= '<p>Khi tiền về tài khoản, bạn bấm nút dưới đây để duyệt. Site sẽ tự tạo tài khoản học, mở khóa học và gửi email cho khách.</p>'
			. maypiano_mail_button( maypiano_approve_url( $order ), 'Duyệt đơn này' );
	}
	$body .= '<p style="font-size:15px"><a href="' . esc_url( $order->get_edit_order_url() ) . '" style="color:#8A3350">Mở đơn trong WooCommerce</a></p>';
	return maypiano_mail( maypiano_notify_address(), maypiano_mail_owner_subject( $order, 'Đơn mới' ), $body );
}

function maypiano_mail_owner_reported( $order ) {
	$body = '<p><strong>Khách báo đã trả tiền.</strong> Kiểm tra tài khoản, thấy đúng số tiền và nội dung <strong>' . esc_html( maypiano_memo( $order ) ) . '</strong> thì bấm nút duyệt.</p>'
		. maypiano_mail_button( maypiano_approve_url( $order ), 'Duyệt đơn này' )
		. maypiano_mail_owner_facts( $order )
		. '<p style="font-size:15px"><a href="' . esc_url( $order->get_edit_order_url() ) . '" style="color:#8A3350">Mở đơn trong WooCommerce</a></p>';
	return maypiano_mail( maypiano_notify_address(), maypiano_mail_owner_subject( $order, 'Khách báo đã trả' ), $body );
}

function maypiano_mail_owner_access_problem( $order, $missing ) {
	$body = '<p><strong>Khách đã trả tiền, nhưng site chưa mở được khóa học:</strong> ' . esc_html( implode( ', ', $missing ) ) . '.</p>'
		. '<p>Lý do thường gặp: khóa này chưa được đưa lên site. Khi khóa có trên site, site tự mở cho khách và gửi email cho khách, bạn không cần làm gì thêm. Muốn thử mở ngay, bạn bấm nút dưới đây.</p>'
		. maypiano_mail_button( maypiano_approve_url( $order ), 'Thử mở khóa học lại' )
		. maypiano_mail_owner_facts( $order )
		. '<p style="font-size:15px"><a href="' . esc_url( $order->get_edit_order_url() ) . '" style="color:#8A3350">Mở đơn trong WooCommerce</a></p>';
	return maypiano_mail( maypiano_notify_address(), maypiano_mail_owner_subject( $order, 'CHƯA MỞ ĐƯỢC KHÓA' ), $body );
}
