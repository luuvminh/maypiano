<?php
/**
 * One-tap approval for the owner: a private link in the owner's emails opens a small phone page
 * where she confirms the money arrived. No sign-in, no WordPress admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function maypiano_approve_key( $order ) {
	return substr( wp_hash( 'mp-approve|' . $order->get_id() . '|' . $order->get_order_key() ), 0, 32 );
}

function maypiano_approve_url( $order ) {
	return add_query_arg( array( 'mp_duyet' => $order->get_id(), 'k' => maypiano_approve_key( $order ) ), home_url( '/' ) );
}

/** Can this order still be approved from the phone page? */
function maypiano_approvable( $order ) {
	return in_array( maypiano_order_state( $order ), array( 'waiting', 'reported' ), true ) && ! $order->get_meta( '_mp_needs_quote' );
}

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['mp_duyet'] ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $_GET['mp_duyet'] ) ) : null;
	$key   = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
	if ( ! $order || ! maypiano_is_ours( $order ) || ! hash_equals( maypiano_approve_key( $order ), $key ) ) {
		status_header( 404 );
		maypiano_approve_page( 'Không tìm thấy đơn', '<p>Đường dẫn này không đúng hoặc đã hỏng. Bạn mở lại từ email mới nhất nhé.</p>' );
	}

	// Mail apps open links on their own to preview them, so opening the page never approves. Only the button does.
	$done = false;
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['mp_ok'] ) && maypiano_approvable( $order ) ) {
		$order->update_status( 'completed', 'Chủ site xác nhận đã nhận tiền qua nút trong email.' );
		$order = wc_get_order( $order->get_id() );
		$done  = true;
	}

	$retried = false;
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['mp_retry'] ) && 'paid' === maypiano_order_state( $order ) ) {
		maypiano_retry_access( $order );
		$order   = wc_get_order( $order->get_id() );
		$retried = true;
	}

	$code   = maypiano_order_code( $order );
	$amount = maypiano_money( $order->get_total(), $order->get_currency() );
	$name   = $order->get_billing_first_name();
	$state  = maypiano_order_state( $order );
	$facts  = '<dl>'
		. '<dt>Khách</dt><dd>' . esc_html( $name ) . '<br><small>' . esc_html( $order->get_billing_email() ) . '</small></dd>'
		. '<dt>Số tiền</dt><dd class="big">' . esc_html( $amount ) . '</dd>'
		. '<dt>Nội dung chuyển khoản</dt><dd class="big">' . esc_html( maypiano_memo( $order ) ) . '</dd>'
		. '<dt>Khóa học</dt><dd>' . esc_html( implode( ', ', array_map( function ( $item ) {
			return $item->get_name();
		}, $order->get_items() ) ) ) . '</dd></dl>';
	$test = $order->get_meta( '_mp_test' ) ? '<p class="note">Đây là đơn chạy thử.</p>' : '';

	if ( 'paid' === $state ) {
		if ( 'open' === $order->get_meta( '_mp_access' ) ) {
			$body = '<p class="ok">' . ( $done || $retried ? 'Xong rồi.' : 'Đơn này đã xong.' ) . '</p>'
				. '<p>Khóa học đã mở cho <strong>' . esc_html( $name ) . '</strong>. Khách đã nhận email hướng dẫn vào học. Bạn đóng trang này được rồi.</p>' . $facts;
			maypiano_approve_page( 'Đã mở khóa học, đơn ' . $code, $test . $body );
		}
		$missing = maypiano_missing_courses( $order );
		$body    = '<p class="warn">Tiền đã nhận, nhưng site chưa mở được: ' . esc_html( implode( ', ', $missing ) ) . '.</p>'
			. ( $retried
				? '<p>Vừa thử lại, vẫn chưa được. Lý do thường gặp: khóa này chưa được đưa lên site. Bạn nhắn Max. Khi khóa có trên site, site tự mở cho khách và gửi email, bạn không cần làm gì thêm.</p>'
				: '<p>Khi khóa này có trên site, site tự mở cho khách và gửi email. Bạn cũng có thể bấm nút để thử mở ngay.</p>' )
			. $facts
			. '<form method="post"><button type="submit" name="mp_retry" value="1">Thử mở khóa học lại</button></form>';
		maypiano_approve_page( 'Chưa mở được khóa, đơn ' . $code, $test . $body );
	}
	if ( ! maypiano_approvable( $order ) ) {
		$why = $order->get_meta( '_mp_needs_quote' ) ? 'Đơn này chưa có giá, cần báo giá cho khách trước.' : 'Đơn này đã hủy hoặc không còn chờ duyệt.';
		maypiano_approve_page( 'Đơn ' . $code, $test . '<p class="warn">' . esc_html( $why ) . '</p>' . $facts );
	}
	$body = '<p>Bạn mở app ngân hàng, thấy <strong>đủ số tiền</strong> và <strong>đúng nội dung</strong> bên dưới thì bấm nút.</p>'
		. $facts
		. '<form method="post"><button type="submit" name="mp_ok" value="1">Đã nhận đủ tiền, mở khóa học</button></form>'
		. '<p class="note">Chưa thấy tiền thì bạn đóng trang này. Đơn vẫn chờ, bạn mở lại email để duyệt sau.</p>';
	maypiano_approve_page( 'Duyệt đơn ' . $code, $test . $body );
} );

/** The whole page: large type and one large button, made for a phone. */
function maypiano_approve_page( $title, $body ) {
	echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title><style>'
		. 'body{margin:0;background:#F6EFEF;color:#2B1E24;font-family:Georgia,"Times New Roman",serif;font-size:19px;line-height:1.55}'
		. 'main{max-width:520px;margin:0 auto;padding:28px 20px 48px}h1{font-size:32px;line-height:1.15;margin:0 0 16px}'
		. 'dl{background:#fff;border:2px solid #2B1E24;border-radius:14px;padding:6px 18px;margin:20px 0}'
		. 'dt{font-size:15px;margin-top:12px}dd{margin:0 0 12px;font-weight:bold;overflow-wrap:anywhere}dd small{font-weight:normal;font-size:15px}dd.big{font-size:26px}'
		. 'button{display:block;width:100%;min-height:64px;border:0;border-radius:999px;background:#C2456B;color:#fff;font:bold 20px Georgia,serif;padding:16px;cursor:pointer}'
		. '.note{font-size:16px}.ok{font-size:24px;font-weight:bold;color:#2F6B3F}.warn{font-weight:bold;color:#9A2B2B}'
		. '</style></head><body><main><div style="font-weight:bold;margin-bottom:18px">Mây Piano</div><h1>' . esc_html( $title ) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

/** Names of the courses on a paid order that the buyer still cannot get into. */
function maypiano_missing_courses( $order ) {
	$user_id = (int) $order->get_customer_id();
	$missing = array();
	foreach ( $order->get_items() as $item ) {
		$course_id = $user_id ? maypiano_course_for( (string) $item->get_meta( '_mp_key' ), (int) $item->get_product_id() ) : 0;
		if ( ! $course_id || ! function_exists( 'tutor_utils' ) || ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
			$missing[] = $item->get_name();
		}
	}
	return $missing;
}

/**
 * Tries again to open the courses of a paid order that could not be opened at payment time.
 * Tells the customer once everything is open.
 *
 * @return bool True when every course on the order is now open.
 */
function maypiano_retry_access( $order ) {
	if ( 'paid' !== maypiano_order_state( $order ) || 'open' === $order->get_meta( '_mp_access' ) || ! function_exists( 'tutor_utils' ) ) {
		return 'open' === $order->get_meta( '_mp_access' );
	}
	$user_id = (int) $order->get_customer_id();
	$opened  = array();
	$missing = array();
	foreach ( $order->get_items() as $item ) {
		$course_id = $user_id ? maypiano_course_for( (string) $item->get_meta( '_mp_key' ), (int) $item->get_product_id() ) : 0;
		if ( $course_id && maypiano_enrol( $course_id, $user_id, $order->get_id(), true ) ) {
			$opened[] = $item->get_name();
		} else {
			$missing[] = $item->get_name();
		}
	}
	if ( $missing ) {
		return false;
	}
	$order->update_meta_data( '_mp_access', 'open' );
	$order->save();
	$order->add_order_note( 'Mở lại thành công, khóa học đã mở cho tài khoản #' . $user_id . ': ' . implode( ', ', $opened ) . '.' );
	maypiano_mail_access( $order, $user_id, $opened, array() );
	return true;
}

/** Paid orders still waiting for a course. */
function maypiano_orders_waiting_for_course() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$found = array();
	foreach ( array( 'manual', 'partial' ) as $access ) {
		$found = array_merge( $found, wc_get_orders( array(
			'limit'      => 50,
			'status'     => 'completed',
			'meta_key'   => '_mp_access', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $access, // phpcs:ignore WordPress.DB.SlowDBQuery
		) ) );
	}
	return $found;
}

/** When a course is saved in Tutor LMS, and once an hour, waiting orders are tried again by themselves. */
function maypiano_retry_waiting_orders() {
	foreach ( maypiano_orders_waiting_for_course() as $order ) {
		if ( maypiano_is_ours( $order ) ) {
			maypiano_retry_access( $order );
		}
	}
}
add_action( 'save_post_courses', function ( $post_id, $post ) {
	if ( 'auto-draft' !== $post->post_status && ! wp_is_post_revision( $post_id ) && ! wp_next_scheduled( 'maypiano_retry_once' ) ) {
		wp_schedule_single_event( time() + 30, 'maypiano_retry_once' );
	}
}, 20, 2 );
add_action( 'maypiano_retry_once', 'maypiano_retry_waiting_orders' );
add_action( 'maypiano_retry_hourly', 'maypiano_retry_waiting_orders' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'maypiano_retry_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'maypiano_retry_hourly' );
	}
} );
