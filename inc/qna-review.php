<?php
/**
 * Trang "Hỏi đáp chờ chị" for the owners: a phone page where Mây (or Max) reads each waiting question,
 * edits the prepared answer or writes her own, and taps Đăng. Only the accounts listed in
 * maypiano_qna_reviewers() can open it, after signing in. A morning email tells Mây when something waits.
 *
 * Posting reuses the rule in inc/qna.php: the entry gets the answer in its excerpt and turns private,
 * and the site posts the answer under the question as "Trợ lý May Piano".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The only accounts that may open the page, by sign-in email. */
function maypiano_qna_reviewers() {
	return array( 'haimay1711@gmail.com', 'maxwelllau@gmail.com' );
}

/** Who gets the morning email. */
function maypiano_qna_digest_to() {
	return array( 'haimay1711@gmail.com' );
}

function maypiano_qna_review_url( $args = array() ) {
	return add_query_arg( array( 'mp_hoidap' => 1 ) + $args, home_url( '/' ) );
}

function maypiano_qna_is_reviewer( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	return $user && $user->exists() && in_array( strtolower( $user->user_email ), maypiano_qna_reviewers(), true );
}

/** The fields written into an entry by maypiano_qna_make_entry(), read back. */
function maypiano_qna_fields( $entry ) {
	$text   = html_entity_decode( wp_strip_all_tags( (string) $entry->post_content ), ENT_QUOTES, 'UTF-8' );
	$fields = array( 'course' => '', 'lesson' => '', 'link' => '', 'asker' => '', 'question' => '' );
	$keys   = array( 'Khóa học' => 'course', 'Bài học' => 'lesson', 'Link bài' => 'link', 'Người hỏi' => 'asker' );
	foreach ( preg_split( '/\R/', $text ) as $line ) {
		foreach ( $keys as $label => $key ) {
			if ( 0 === strpos( $line, $label . ': ' ) ) {
				$fields[ $key ] = trim( substr( $line, strlen( $label ) + 2 ) );
			}
		}
	}
	$at = strpos( $text, "Câu hỏi:\n" );
	if ( false === $at ) {
		$at = strpos( $text, 'Câu hỏi:' );
	}
	$fields['question'] = false === $at ? $text : trim( substr( $text, $at + strlen( 'Câu hỏi:' ) ) );
	$fields['link']     = str_replace( '&amp;', '&', $fields['link'] );
	return $fields;
}

/** Entries waiting: those with a prepared answer first, then those Mây answers herself. */
function maypiano_qna_waiting() {
	$get = function ( $status ) {
		return get_posts( array(
			'post_type'      => MAYPIANO_QNA_TYPE,
			'post_status'    => $status,
			'posts_per_page' => 50,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
	};
	return array( 'draft' => $get( 'draft' ), 'pending' => $get( 'pending' ) );
}

function maypiano_qna_review_page( $title, $body, $code = 200 ) {
	status_header( $code );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title><style>'
		. 'body{margin:0;background:#F6EFEF;color:#2B1E24;font-family:Georgia,"Times New Roman",serif;font-size:18px;line-height:1.55}'
		. 'main{max-width:560px;margin:0 auto;padding:24px 16px 48px}h1{font-size:30px;line-height:1.15;margin:0 0 8px}'
		. 'h2{font:bold 14px -apple-system,"Segoe UI",sans-serif;letter-spacing:.06em;text-transform:uppercase;color:#7A6670;margin:28px 0 10px}'
		. '.card{background:#fff;border:2px solid #2B1E24;border-radius:14px;padding:14px 16px;margin:0 0 14px}'
		. '.meta{font:14px/1.45 -apple-system,"Segoe UI",sans-serif;color:#7A6670;margin-bottom:6px}.meta a{color:#C2456B}'
		. '.q{font-weight:bold;margin:0 0 10px;white-space:pre-line}'
		. 'label{display:block;font:600 14px -apple-system,"Segoe UI",sans-serif;color:#7A6670;margin-bottom:4px}'
		. 'textarea{width:100%;box-sizing:border-box;min-height:120px;border:1.5px solid #E4D6D9;border-radius:10px;padding:10px;font:17px/1.5 Georgia,serif;background:#F6EFEF;color:#2B1E24}'
		. 'button{display:block;width:100%;min-height:58px;margin-top:10px;border:0;border-radius:999px;background:#C2456B;color:#fff;font:bold 19px Georgia,serif;cursor:pointer}'
		. 'button.ghost{background:transparent;color:#2B1E24;border:2px solid #2B1E24;min-height:48px;font-size:16px}'
		. '.ok{background:#EAF4EC;border:2px solid #2F6B3F;color:#2F6B3F;border-radius:12px;padding:10px 14px;font-weight:bold;margin:12px 0}'
		. '.warn{background:#FBEAEA;border:2px solid #9A2B2B;color:#9A2B2B;border-radius:12px;padding:10px 14px;font-weight:bold;margin:12px 0}'
		. '.empty{color:#7A6670}.small{font-size:15px;color:#7A6670}'
		. '</style></head><body><main><div style="font-weight:bold;margin-bottom:14px">Mây Piano</div><h1>' . esc_html( $title ) . '</h1>' . $body . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

function maypiano_qna_review_card( $entry, $own ) {
	$f    = maypiano_qna_fields( $entry );
	$id   = (int) $entry->ID;
	$link = $f['link'] ? '<a href="' . esc_url( $f['link'] ) . '">' . esc_html( $f['lesson'] ? $f['lesson'] : 'Mở bài' ) . '</a>' : esc_html( $f['lesson'] );
	$html = '<form class="card" method="post" action="' . esc_url( maypiano_qna_review_url() ) . '#m' . $id . '" id="m' . $id . '">'
		. '<div class="meta">Mục ' . $id . ' · ' . esc_html( $f['asker'] ) . ' · ' . esc_html( $f['course'] ) . ' · ' . $link . '</div>'
		. '<p class="q">' . esc_html( $f['question'] ) . '</p>'
		. '<label for="t' . $id . '">' . ( $own ? 'Câu trả lời của chị' : 'Nháp trả lời (chị sửa được)' ) . '</label>'
		. '<textarea id="t' . $id . '" name="answer" placeholder="Gõ, hoặc bấm micro trên bàn phím để nói…">' . esc_textarea( $own ? '' : (string) $entry->post_excerpt ) . '</textarea>'
		. '<input type="hidden" name="entry" value="' . $id . '">'
		. wp_nonce_field( 'mp_hoidap_' . $id, '_wpnonce', false, false )
		. '<button type="submit" name="mp_dang" value="1">Đăng</button>'
		. '</form>';
	return $html;
}

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['mp_hoidap'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( maypiano_qna_review_url() ) );
		exit;
	}
	$me = wp_get_current_user();
	if ( ! maypiano_qna_is_reviewer( $me ) ) {
		maypiano_qna_review_page( 'Tài khoản này không vào được', '<p>Bạn đang đăng nhập bằng <strong>' . esc_html( $me->user_email ) . '</strong>. Trang này chỉ dành cho chủ site.</p>'
			. '<form method="post" action="' . esc_url( maypiano_account_url( 'doi-tai-khoan' ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'log-out' ) ) . '">'
			. '<input type="hidden" name="ve" value="' . esc_attr( wp_make_link_relative( maypiano_qna_review_url() ) ) . '"><button type="submit">Đổi tài khoản</button></form>', 403 );
	}

	$notice = '';
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$id     = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		$entry  = $id ? get_post( $id ) : null;
		$answer = isset( $_POST['answer'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) ) : '';
		if ( isset( $_POST['mp_thu'] ) && check_admin_referer( 'mp_hoidap_thu' ) ) {
			$sent = maypiano_qna_send_digest( true );
			wp_safe_redirect( maypiano_qna_review_url( array( 'thu' => $sent ? 1 : 0 ) ) );
			exit;
		}
		if ( ! $entry || MAYPIANO_QNA_TYPE !== $entry->post_type || ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '', 'mp_hoidap_' . $id ) ) {
			$notice = '<div class="warn">Trang đã cũ. Chị tải lại trang rồi bấm Đăng lần nữa nhé.</div>';
		} elseif ( ! in_array( $entry->post_status, array( 'draft', 'pending' ), true ) || get_post_meta( $id, '_mp_posted', true ) ) {
			wp_safe_redirect( maypiano_qna_review_url( array( 'xong' => $id ) ) );
			exit;
		} elseif ( '' === $answer ) {
			$notice = '<div class="warn">Mục ' . $id . ': chị viết câu trả lời trước rồi mới bấm Đăng nhé.</div>';
		} else {
			update_post_meta( $id, '_mp_reviewed_by', (int) $me->ID );
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $answer, 'post_status' => 'private' ) );
			$posted = get_post_meta( $id, '_mp_posted', true );
			wp_safe_redirect( maypiano_qna_review_url( $posted && 'pending' !== $posted ? array( 'xong' => $id ) : array( 'loi' => $id ) ) );
			exit;
		}
	}

	if ( isset( $_GET['xong'] ) ) {
		$notice .= '<div class="ok">Đã đăng mục ' . absint( $_GET['xong'] ) . '. Học viên thấy ngay.</div>';
	}
	if ( isset( $_GET['loi'] ) ) {
		$notice .= '<div class="warn">Mục ' . absint( $_GET['loi'] ) . ' chưa đăng được lên dưới câu hỏi. Chị báo anh Max kiểm tra nhé.</div>';
	}
	if ( isset( $_GET['thu'] ) ) {
		$notice .= absint( $_GET['thu'] ) ? '<div class="ok">Đã gửi email thử.</div>' : '<div class="warn">Không gửi được email thử.</div>';
	}

	$wait = maypiano_qna_waiting();
	$body = $notice;
	if ( ! $wait['draft'] && ! $wait['pending'] ) {
		$body .= '<p class="empty">Không có câu hỏi nào đang chờ. Chị nghỉ ngơi nhé.</p>';
	}
	if ( $wait['draft'] ) {
		$body .= '<h2>Có nháp sẵn (' . count( $wait['draft'] ) . ')</h2>';
		foreach ( $wait['draft'] as $entry ) {
			$body .= maypiano_qna_review_card( $entry, false );
		}
	}
	if ( $wait['pending'] ) {
		$body .= '<h2>Chị trả lời (' . count( $wait['pending'] ) . ')</h2>';
		foreach ( $wait['pending'] as $entry ) {
			$body .= maypiano_qna_review_card( $entry, true );
		}
	}
	$body .= '<p class="small">Bấm Đăng là câu trả lời hiện ngay dưới câu hỏi, tên Trợ lý May Piano. Đăng rồi thì không gỡ ở đây được.</p>'
		. '<form method="post" action="' . esc_url( maypiano_qna_review_url() ) . '">' . wp_nonce_field( 'mp_hoidap_thu', '_wpnonce', false, false )
		. '<button class="ghost" type="submit" name="mp_thu" value="1">Gửi thử email báo</button></form>';
	maypiano_qna_review_page( 'Hỏi đáp chờ chị', $body );
}, 5 );

/**
 * The morning email. Sent once a day after 8:00 Melbourne time, only when something waits.
 * $test sends it now, whatever the hour, and even when nothing waits.
 */
function maypiano_qna_send_digest( $test = false ) {
	$wait = maypiano_qna_waiting();
	$d    = count( $wait['draft'] );
	$p    = count( $wait['pending'] );
	if ( ! $test && 0 === $d + $p ) {
		return false;
	}
	$lines = array();
	if ( $d ) {
		$lines[] = $d . ' câu có nháp sẵn, chị đọc rồi bấm Đăng.';
	}
	if ( $p ) {
		$lines[] = $p . ' câu cần chị tự trả lời.';
	}
	if ( ! $lines ) {
		$lines[] = 'Hiện không có câu nào chờ. Đây là email thử.';
	}
	$total = $d + $p;
	$body  = '<p><strong>' . ( $total ? 'Hôm nay có ' . $total . ' câu hỏi chờ chị.' : 'Email thử.' ) . '</strong></p>'
		. '<p>' . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</p>'
		. maypiano_mail_button( maypiano_qna_review_url(), 'Mở trang duyệt' )
		. '<p style="font-size:14px;color:#7A6670">Trang này cần đăng nhập bằng tài khoản của chị.</p>';
	return (bool) maypiano_mail( maypiano_qna_digest_to(), ( $test ? '[Thử] ' : '' ) . 'Mây Piano: ' . ( $total ? $total . ' câu hỏi chờ chị' : 'không có câu hỏi chờ' ), $body );
}

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'maypiano_qna_digest_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'maypiano_qna_digest_hourly' );
	}
} );

add_action( 'maypiano_qna_digest_hourly', function () {
	$now = new DateTime( 'now', new DateTimeZone( 'Australia/Melbourne' ) );
	$day = $now->format( 'Y-m-d' );
	if ( (int) $now->format( 'G' ) < 8 || get_option( 'maypiano_qna_digest_day' ) === $day ) {
		return;
	}
	update_option( 'maypiano_qna_digest_day', $day, false );
	maypiano_qna_send_digest();
} );
