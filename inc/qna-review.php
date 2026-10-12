<?php
/**
 * "Hỏi đáp" — the owners' mini app for learners' questions, at /?mp_hoidap=1.
 *
 * A phone-first page in the May Piano look where Mây (or Max) handles every question learners ask under a lesson:
 *   Chờ trả lời  — questions waiting: read, edit the prepared answer or write one (keyboard microphone works), Đăng.
 *                  Also: Lưu nháp, Để dành (keep for the monthly video), Ẩn (hide a question from everyone).
 *   Để dành      — questions kept for the monthly video; Đăng still works, or move them back.
 *   Đã trả lời   — answered questions with their answers: edit an answer, or take it down (the question then goes
 *                  back to waiting and is private again). Hidden questions can be shown again here.
 *   Cài đặt      — the email that tells Mây when something waits: on/off, who gets it, the hour; send a test.
 * Search and a course filter work on every list. The page can be added to the iPhone home screen as an app.
 *
 * Who sees what on the site (inc/qna.php): a question nobody has answered is seen by its asker and Mây only;
 * once answered it is seen by every learner of the course.
 *
 * Only the accounts in maypiano_qna_reviewers() can open it, after signing in.
 * Posting reuses the rule in inc/qna.php: the entry gets the answer in its excerpt and turns private, and the site
 * posts the answer under the question as "Trợ lý May Piano".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The only accounts that may open the page, by sign-in email. */
function maypiano_qna_reviewers() {
	return array( 'haimay1711@gmail.com', 'maxwelllau@gmail.com' );
}

/** The email settings, with their defaults. */
function maypiano_qna_mail_settings() {
	$to = array_values( array_filter( array_map( 'trim', explode( ',', (string) get_option( 'maypiano_qna_mail_to', 'haimay1711@gmail.com' ) ) ), 'is_email' ) );
	return array(
		'on'   => '0' !== (string) get_option( 'maypiano_qna_mail_on', '1' ),
		'to'   => $to,
		'hour' => min( 23, max( 0, (int) get_option( 'maypiano_qna_mail_hour', 8 ) ) ),
	);
}

/** Who gets the email. */
function maypiano_qna_digest_to() {
	return maypiano_qna_mail_settings()['to'];
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

/** Entries by list. Each list holds at most 200, newest last for waiting lists and newest first for the answered ones. */
function maypiano_qna_list( $which ) {
	$later = array( 'key' => '_mp_later', 'compare' => 'EXISTS' );
	$args  = array(
		'post_type'      => MAYPIANO_QNA_TYPE,
		'posts_per_page' => 200,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);
	switch ( $which ) {
		case 'draft':
		case 'pending':
			$args['post_status'] = $which;
			$args['meta_query']  = array( array( 'key' => '_mp_later', 'compare' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			break;
		case 'later':
			$args['post_status'] = array( 'draft', 'pending' );
			$args['meta_query']  = array( $later ); // phpcs:ignore WordPress.DB.SlowDBQuery
			break;
		case 'hidden':
			$args['post_status'] = 'private';
			$args['order']       = 'DESC';
			$args['meta_query']  = array( array( 'key' => '_mp_posted', 'value' => 'hidden' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			break;
		default: // answered
			$args['post_status'] = 'private';
			$args['orderby']     = 'modified';
			$args['order']       = 'DESC';
			$args['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array( 'key' => '_mp_question', 'compare' => 'EXISTS' ),
				array(
					'relation' => 'OR',
					array( 'key' => '_mp_posted', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_mp_posted', 'value' => 'hidden', 'compare' => '!=' ),
				),
			);
	}
	return get_posts( $args );
}

/** Kept for older callers: the two waiting lists. */
function maypiano_qna_waiting() {
	return array( 'draft' => maypiano_qna_list( 'draft' ), 'pending' => maypiano_qna_list( 'pending' ) );
}

/** Keeps the entries that match the search words and the chosen course. */
function maypiano_qna_filter( $entries, $q, $course ) {
	if ( '' === $q && '' === $course ) {
		return $entries;
	}
	$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );
	return array_values( array_filter( $entries, function ( $e ) use ( $q, $course ) {
		$f = maypiano_qna_fields( $e );
		if ( '' !== $course && $f['course'] !== $course ) {
			return false;
		}
		if ( '' === $q ) {
			return true;
		}
		$hay = implode( ' ', array( $f['question'], $f['asker'], $f['lesson'], (string) $e->post_excerpt, (string) $e->ID ) );
		$hay = function_exists( 'mb_strtolower' ) ? mb_strtolower( $hay ) : strtolower( $hay );
		return false !== strpos( $hay, $q );
	} ) );
}

/** The answers under an entry's question, oldest first. */
function maypiano_qna_answers_of( $entry ) {
	$qid = (int) get_post_meta( $entry->ID, '_mp_question', true );
	if ( ! $qid ) {
		return array();
	}
	return get_comments( array( 'parent' => $qid, 'type' => 'tutor_q_and_a', 'orderby' => 'comment_ID', 'order' => 'ASC', 'status' => 'all' ) );
}

/*
 * The page.
 */

function maypiano_qna_icon( $name ) {
	$p = array(
		'cho'     => '<path d="M4 5h16v11H8l-4 4z"/>',
		'de-danh' => '<path d="M6 3h12v18l-6-4-6 4z"/>',
		'da'      => '<path d="M5 12l5 5L20 7"/>',
		'cai-dat' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
		'tim'     => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>',
	);
	return '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ( $p[ $name ] ?? '' ) . '</svg>';
}

function maypiano_qna_review_page( $title, $body, $code = 200, $tabs = '' ) {
	status_header( $code );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	$icon = get_theme_file_uri( 'assets/img/apple-touch-icon.png' );
	echo '<!doctype html><html lang="vi"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">'
		. '<meta name="theme-color" content="#E3D7D7"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">'
		. '<meta name="apple-mobile-web-app-title" content="Hỏi đáp"><meta name="apple-mobile-web-app-status-bar-style" content="default">'
		. '<link rel="apple-touch-icon" href="' . esc_url( $icon ) . '"><link rel="icon" href="' . esc_url( get_theme_file_uri( 'assets/img/icon-32.png' ) ) . '">'
		. '<link rel="manifest" href="' . esc_url( maypiano_qna_review_url( array( 'app' => 'manifest' ) ) ) . '">'
		. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
		. '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Newsreader:opsz,wght@6..72,400;6..72,600&display=swap">' // phpcs:ignore WordPress.WP.EnqueuedResources
		. '<title>' . esc_html( $title ) . ' · Mây Piano</title><style>' . maypiano_qna_review_css() . '</style></head><body>'
		. '<header class="top"><a class="brand" href="' . esc_url( maypiano_qna_review_url() ) . '"><img src="' . esc_url( get_theme_file_uri( 'assets/img/logo.png' ) ) . '" alt="May Piano" width="55" height="32"></a><span class="top-title">Hỏi đáp</span></header>'
		. '<main><h1>' . esc_html( $title ) . '</h1>' . $body . '</main>' . $tabs
		. '<script>' . maypiano_qna_review_js() . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

function maypiano_qna_review_css() {
	return ':root{--ink:#2B1E24;--accent:#C2456B;--plum:#5B2A3C;--bg:#E3D7D7;--blush:#F6EFEF;--pink:#E9B9C6;--muted:#7A6670;--hair:rgba(43,30,36,.16);--ok:#2F6B3F;--bad:#9A2B2B;--ease:cubic-bezier(.22,1,.36,1);--head:"Playfair Display",Georgia,serif;--body:"Newsreader",Georgia,serif;--ui:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
		. '*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}'
		. 'body{margin:0;background:var(--bg);color:var(--ink);font:18px/1.55 var(--body);font-variant-numeric:lining-nums;padding-bottom:calc(84px + env(safe-area-inset-bottom))}'
		. 'a{color:var(--ink)}:where(a,button,input,select,textarea,summary):focus-visible{outline:3px solid var(--ink);outline-offset:3px}'
		. '.top{position:sticky;top:0;z-index:5;display:flex;align-items:center;gap:10px;padding:calc(10px + env(safe-area-inset-top)) 16px 10px;background:rgba(227,215,215,.82);-webkit-backdrop-filter:saturate(1.6) blur(18px);backdrop-filter:saturate(1.6) blur(18px);border-bottom:1px solid var(--hair)}'
		. '.brand img{display:block;height:32px;width:auto}.top-title{font:600 15px var(--ui);letter-spacing:.04em;text-transform:uppercase;color:var(--muted)}'
		. 'main{max-width:620px;margin:0 auto;padding:18px 16px 32px}'
		. 'h1{font:600 clamp(32px,8vw,42px)/1.08 var(--head);letter-spacing:-.01em;margin:6px 0 14px}'
		. 'h2{font:700 13px var(--ui);letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:26px 0 10px}'
		. '.lead{color:var(--muted);font-size:16px;margin:-6px 0 14px}'
		. '.tools{display:flex;gap:8px;margin:0 0 6px}.tools .search{flex:1;position:relative}.tools .search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);width:20px;height:20px}'
		. 'input[type=search],input[type=text],input[type=email],select{width:100%;min-height:48px;border:1.5px solid var(--hair);border-radius:12px;background:var(--blush);color:var(--ink);font:17px var(--body);padding:10px 12px}'
		. '.search input{padding-left:40px}.tools select{width:auto;max-width:44%}'
		. '.card{background:#fff;border:2px solid var(--ink);border-radius:16px;padding:14px 16px 16px;margin:0 0 14px;scroll-margin-top:80px}'
		. '.meta{font:14px/1.45 var(--ui);color:var(--muted);margin-bottom:6px}.meta a{color:var(--accent)}.meta .who{color:var(--ink);font-weight:600}'
		. '.q{font-weight:600;margin:0 0 12px;white-space:pre-line}'
		. 'label,.lbl{display:block;font:600 14px var(--ui);color:var(--muted);margin-bottom:4px}'
		. 'textarea{width:100%;min-height:118px;border:1.5px solid var(--hair);border-radius:12px;padding:10px 12px;font:17px/1.5 var(--body);background:var(--blush);color:var(--ink);resize:vertical}'
		. 'textarea:focus,input:focus,select:focus{border-color:var(--ink);outline:none}'
		. 'button,.btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:56px;margin-top:10px;border:0;border-radius:999px;background:var(--accent);color:#fff;font:700 19px var(--body);cursor:pointer;text-decoration:none;transition:transform .2s var(--ease),opacity .2s}'
		. 'button:active{transform:scale(.98)}button[disabled]{opacity:.6}'
		. '.row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.row button{flex:1 1 0;min-width:96px;min-height:44px;margin:0;font-size:16px}'
		. 'button.ghost{background:transparent;color:var(--ink);border:1.5px solid var(--ink)}button.quiet{background:transparent;color:var(--muted);border:1.5px solid var(--hair)}button.danger{background:transparent;color:var(--bad);border:1.5px solid var(--bad)}'
		. '.ans{border-left:3px solid var(--pink);padding:2px 0 2px 12px;margin:10px 0}.ans .by{font:600 14px var(--ui);color:var(--muted)}.ans p{margin:4px 0}'
		. 'details.edit summary{list-style:none;cursor:pointer;font:600 15px var(--ui);color:var(--accent);padding:8px 0}details.edit summary::-webkit-details-marker{display:none}'
		. '.ok,.warn{border-radius:14px;padding:12px 14px;font-weight:600;margin:4px 0 14px}.ok{background:#EAF4EC;border:2px solid var(--ok);color:var(--ok)}.warn{background:#FBEAEA;border:2px solid var(--bad);color:var(--bad)}'
		. '.empty{text-align:center;color:var(--muted);padding:40px 12px}.empty strong{display:block;font:600 24px var(--head);color:var(--ink);margin-bottom:6px}'
		. '.small{font-size:15px;color:var(--muted)}.pill{display:inline-block;font:600 12px var(--ui);padding:2px 8px;border-radius:999px;background:var(--pink);color:var(--plum);vertical-align:2px}'
		. '.set{background:#fff;border:2px solid var(--ink);border-radius:16px;padding:6px 16px 16px;margin-bottom:14px}.set .f{padding:12px 0;border-bottom:1px solid var(--hair)}.set .f:last-of-type{border-bottom:0}'
		. '.switch{display:flex;align-items:center;justify-content:space-between;gap:12px;font:17px var(--body);color:var(--ink)}.switch input{appearance:none;-webkit-appearance:none;width:52px;height:32px;border-radius:999px;background:var(--hair);position:relative;transition:background .25s var(--ease);flex:none;margin:0}'
		. '.switch input::after{content:"";position:absolute;top:3px;left:3px;width:26px;height:26px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .25s var(--ease)}.switch input:checked{background:var(--accent)}.switch input:checked::after{transform:translateX(20px)}'
		. '.more{display:block;text-align:center;margin:8px 0 0;font:600 16px var(--ui);color:var(--accent)}'
		. 'nav.tabs{position:fixed;left:0;right:0;bottom:0;z-index:5;display:flex;justify-content:center;background:rgba(246,239,239,.86);-webkit-backdrop-filter:saturate(1.6) blur(18px);backdrop-filter:saturate(1.6) blur(18px);border-top:1px solid var(--hair);padding:6px 4px calc(6px + env(safe-area-inset-bottom))}'
		. 'nav.tabs a{position:relative;flex:1;max-width:150px;display:flex;flex-direction:column;align-items:center;gap:2px;padding:4px 2px;text-decoration:none;color:var(--muted);font:600 12px var(--ui)}'
		. 'nav.tabs a[aria-current]{color:var(--accent)}nav.tabs .b{position:absolute;top:0;left:calc(50% + 6px);min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--accent);color:#fff;font:700 12px/20px var(--ui);text-align:center}'
		. '@media (prefers-reduced-motion:reduce){*{transition:none!important}}'
		. '@media (prefers-contrast:more){:root{--hair:var(--ink);--muted:#4A3A41}}';
}

function maypiano_qna_review_js() {
	return '(function(){'
		. 'document.querySelectorAll("form[data-ask]").forEach(function(f){f.addEventListener("submit",function(e){var b=e.submitter;if(b&&b.dataset.ask&&!confirm(b.dataset.ask)){e.preventDefault();return;}'
		. 'f.querySelectorAll("button").forEach(function(x){if(x!==b){x.disabled=true;}});if(b){var keep=document.createElement("input");keep.type="hidden";keep.name=b.name;keep.value=b.value;f.appendChild(keep);setTimeout(function(){b.disabled=true;b.textContent="Đang lưu…";},0);}});});'
		. 'document.querySelectorAll("textarea").forEach(function(t){function fit(){t.style.height="auto";t.style.height=Math.max(118,t.scrollHeight+4)+"px";}t.addEventListener("input",fit);fit();});'
		. 'var s=document.querySelector(".tools select");if(s){s.addEventListener("change",function(){s.form.submit();});}'
		. '})();';
}

/** The bottom tab bar. */
function maypiano_qna_tabs( $on, $counts ) {
	$tabs = array(
		'cho'     => array( 'Chờ trả lời', $counts['cho'] ),
		'de-danh' => array( 'Để dành', $counts['de-danh'] ),
		'da'      => array( 'Đã trả lời', 0 ),
		'cai-dat' => array( 'Cài đặt', 0 ),
	);
	$html = '<nav class="tabs" aria-label="Các mục">';
	foreach ( $tabs as $key => $t ) {
		$html .= '<a href="' . esc_url( maypiano_qna_review_url( 'cho' === $key ? array() : array( 'tab' => $key ) ) ) . '"' . ( $on === $key ? ' aria-current="page"' : '' ) . '>'
			. maypiano_qna_icon( $key ) . esc_html( $t[0] ) . ( $t[1] ? '<span class="b">' . (int) $t[1] . '</span>' : '' ) . '</a>';
	}
	return $html . '</nav>';
}

/** The search box and course filter of a list. */
function maypiano_qna_tools( $tab, $q, $course, $courses ) {
	$html = '<form class="tools" method="get" action="' . esc_url( home_url( '/' ) ) . '" role="search">'
		. '<input type="hidden" name="mp_hoidap" value="1">' . ( 'cho' !== $tab ? '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">' : '' )
		. '<div class="search">' . maypiano_qna_icon( 'tim' ) . '<input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Tìm câu hỏi, tên, bài…" aria-label="Tìm"></div>';
	if ( count( $courses ) > 1 || '' !== $course ) {
		$html .= '<select name="khoa" aria-label="Khóa học"><option value="">Mọi khóa</option>';
		foreach ( $courses as $c ) {
			$html .= '<option value="' . esc_attr( $c ) . '"' . selected( $c, $course, false ) . '>' . esc_html( $c ) . '</option>';
		}
		$html .= '</select>';
	}
	return $html . '</form>';
}

function maypiano_qna_meta_line( $entry ) {
	$f    = maypiano_qna_fields( $entry );
	$link = $f['link'] ? '<a href="' . esc_url( $f['link'] ) . '">' . esc_html( $f['lesson'] ? $f['lesson'] : 'Mở bài' ) . '</a>' : esc_html( $f['lesson'] );
	$ago  = human_time_diff( (int) get_post_time( 'U', true, $entry ) ) . ' trước';
	return '<div class="meta"><span class="who">' . esc_html( $f['asker'] ) . '</span> · ' . esc_html( $f['course'] ) . ' · ' . $link . '<br>Mục ' . (int) $entry->ID . ' · ' . esc_html( $ago ) . '</div>'
		. '<p class="q">' . esc_html( $f['question'] ) . '</p>';
}

function maypiano_qna_form_open( $entry, $tab ) {
	$id = (int) $entry->ID;
	return '<form class="card" method="post" data-ask="1" action="' . esc_url( maypiano_qna_review_url( 'cho' === $tab ? array() : array( 'tab' => $tab ) ) ) . '#m' . $id . '" id="m' . $id . '">'
		. '<input type="hidden" name="entry" value="' . $id . '">' . wp_nonce_field( 'mp_hoidap_' . $id, '_wpnonce', false, false );
}

/** A waiting question (also used for the kept ones). */
function maypiano_qna_waiting_card( $entry, $tab ) {
	$id    = (int) $entry->ID;
	$draft = 'draft' === $entry->post_status;
	$later = (bool) get_post_meta( $id, '_mp_later', true );
	return maypiano_qna_form_open( $entry, $tab ) . maypiano_qna_meta_line( $entry )
		. '<label for="t' . $id . '">' . ( $draft ? 'Nháp trả lời <span class="pill">soạn sẵn</span>' : 'Câu trả lời của chị' ) . '</label>'
		. '<textarea id="t' . $id . '" name="answer" placeholder="Gõ, hoặc bấm micro trên bàn phím để nói…">' . esc_textarea( (string) $entry->post_excerpt ) . '</textarea>'
		. '<button type="submit" name="do" value="dang">Đăng</button>'
		. '<div class="row">'
		. '<button class="ghost" type="submit" name="do" value="luu">Lưu nháp</button>'
		. ( $later ? '<button class="ghost" type="submit" name="do" value="bo-de-danh">Trả về Chờ</button>' : '<button class="ghost" type="submit" name="do" value="de-danh">Để dành</button>' )
		. '<button class="quiet" type="submit" name="do" value="an" data-ask="Ẩn câu hỏi này? Không ai thấy nó nữa, kể cả người hỏi. Chị hiện lại được trong mục Đã trả lời.">Ẩn</button>'
		. '</div></form>';
}

/** An answered question with its answers. */
function maypiano_qna_answered_card( $entry ) {
	$id     = (int) $entry->ID;
	$posted = (int) get_post_meta( $id, '_mp_posted', true );
	$html   = maypiano_qna_form_open( $entry, 'da' ) . maypiano_qna_meta_line( $entry );
	$mine   = null;
	foreach ( maypiano_qna_answers_of( $entry ) as $a ) {
		$is_mine = $posted && (int) $a->comment_ID === $posted;
		if ( $is_mine ) {
			$mine = $a;
		}
		$html .= '<div class="ans"><div class="by">' . esc_html( $a->comment_author ) . ' · ' . esc_html( human_time_diff( strtotime( $a->comment_date_gmt . ' UTC' ) ) . ' trước' ) . '</div>' . wp_kses_post( wpautop( $a->comment_content ) ) . '</div>';
	}
	if ( $mine ) {
		$text  = trim( html_entity_decode( wp_strip_all_tags( (string) $mine->comment_content ), ENT_QUOTES, 'UTF-8' ) );
		$html .= '<details class="edit"><summary>Sửa hoặc gỡ câu trả lời</summary>'
			. '<label for="t' . $id . '">Câu trả lời</label><textarea id="t' . $id . '" name="answer">' . esc_textarea( $text ) . '</textarea>'
			. '<button type="submit" name="do" value="sua">Lưu sửa</button>'
			. '<div class="row"><button class="danger" type="submit" name="do" value="go" data-ask="Gỡ câu trả lời này khỏi site? Câu hỏi quay về Chờ trả lời và các học viên khác không thấy nó nữa.">Gỡ câu trả lời</button></div>'
			. '</details>';
	} elseif ( 'person' === get_post_meta( $id, '_mp_posted', true ) ) {
		$html .= '<p class="small">Câu này chị đã trả lời thẳng trên site. Muốn sửa thì chị sửa ở bài học.</p>';
	}
	return $html . '</form>';
}

/** A hidden question. */
function maypiano_qna_hidden_card( $entry ) {
	return maypiano_qna_form_open( $entry, 'da' ) . maypiano_qna_meta_line( $entry )
		. '<button class="ghost" type="submit" name="do" value="hien">Hiện lại</button></form>';
}

/*
 * What the buttons do.
 */

/** Runs one action on one entry. Returns array( notice class, notice text ). */
function maypiano_qna_act( $do, $entry, $answer, $me ) {
	$id  = (int) $entry->ID;
	$qid = (int) get_post_meta( $id, '_mp_question', true );
	$waiting = in_array( $entry->post_status, array( 'draft', 'pending' ), true );
	switch ( $do ) {
		case 'dang':
			if ( ! $waiting || get_post_meta( $id, '_mp_posted', true ) ) {
				return array( 'ok', 'Mục ' . $id . ' đã được trả lời rồi.' );
			}
			if ( '' === $answer ) {
				return array( 'warn', 'Mục ' . $id . ': chị viết câu trả lời trước rồi mới bấm Đăng nhé.' );
			}
			update_post_meta( $id, '_mp_reviewed_by', (int) $me->ID );
			delete_post_meta( $id, '_mp_later' );
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $answer, 'post_status' => 'private' ) );
			if ( ! $qid ) {
				return array( 'ok', 'Đã ghi mục ' . $id . '. Đây là câu hỏi thử nên không hiện cho học viên nào.' );
			}
			$posted = get_post_meta( $id, '_mp_posted', true );
			return $posted && 'pending' !== $posted
				? array( 'ok', 'Đã đăng mục ' . $id . '. Mọi học viên của khóa thấy ngay.' )
				: array( 'warn', 'Mục ' . $id . ' chưa đăng được lên dưới câu hỏi. Chị báo anh Max kiểm tra nhé.' );
		case 'luu':
			if ( ! $waiting ) {
				return array( 'warn', 'Mục ' . $id . ' không còn chờ trả lời.' );
			}
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $answer, 'post_status' => '' === $answer ? 'pending' : 'draft' ) );
			return array( 'ok', 'Đã lưu nháp mục ' . $id . '. Chưa đăng.' );
		case 'de-danh':
			if ( '' !== $answer ) {
				wp_update_post( array( 'ID' => $id, 'post_excerpt' => $answer, 'post_status' => 'draft' ) );
			}
			update_post_meta( $id, '_mp_later', 1 );
			return array( 'ok', 'Đã để dành mục ' . $id . ' cho video giải đáp.' );
		case 'bo-de-danh':
			delete_post_meta( $id, '_mp_later' );
			return array( 'ok', 'Mục ' . $id . ' đã về Chờ trả lời.' );
		case 'an':
			if ( $qid ) {
				update_comment_meta( $qid, 'tutor_qna_archived', 1 );
			}
			update_post_meta( $id, '_mp_hidden_from', $entry->post_status );
			update_post_meta( $id, '_mp_posted', 'hidden' );
			delete_post_meta( $id, '_mp_later' );
			wp_update_post( array( 'ID' => $id, 'post_status' => 'private' ) );
			return array( 'ok', 'Đã ẩn mục ' . $id . '. Chị hiện lại được ở cuối mục Đã trả lời.' );
		case 'hien':
			if ( 'hidden' !== get_post_meta( $id, '_mp_posted', true ) ) {
				return array( 'ok', 'Mục ' . $id . ' đang hiện rồi.' );
			}
			if ( $qid ) {
				delete_comment_meta( $qid, 'tutor_qna_archived' );
			}
			$back = get_post_meta( $id, '_mp_hidden_from', true );
			delete_post_meta( $id, '_mp_posted' );
			delete_post_meta( $id, '_mp_hidden_from' );
			if ( in_array( $back, array( 'draft', 'pending' ), true ) ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => $back ) );
				return array( 'ok', 'Mục ' . $id . ' đã hiện lại và về Chờ trả lời.' );
			}
			update_post_meta( $id, '_mp_posted', 'person' );
			return array( 'ok', 'Mục ' . $id . ' đã hiện lại.' );
		case 'sua':
		case 'go':
			$aid    = (int) get_post_meta( $id, '_mp_posted', true );
			$answer_c = $aid ? get_comment( $aid ) : null;
			if ( ! $answer_c || (int) $answer_c->comment_parent !== $qid ) {
				return array( 'warn', 'Không tìm thấy câu trả lời của mục ' . $id . '.' );
			}
			if ( 'sua' === $do ) {
				if ( '' === $answer ) {
					return array( 'warn', 'Câu trả lời đang trống. Muốn bỏ hẳn thì chị bấm Gỡ câu trả lời.' );
				}
				wp_update_comment( array( 'comment_ID' => $aid, 'comment_content' => wp_kses_post( wpautop( esc_html( $answer ) ) ) ) );
				global $wpdb;
				$wpdb->update( $wpdb->posts, array( 'post_excerpt' => $answer ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
				clean_post_cache( $id );
				return array( 'ok', 'Đã sửa câu trả lời mục ' . $id . '. Học viên thấy chữ mới ngay.' );
			}
			wp_delete_comment( $aid, true );
			delete_post_meta( $id, '_mp_posted' );
			wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			return array( 'ok', 'Đã gỡ câu trả lời mục ' . $id . '. Câu hỏi đã về Chờ trả lời, chữ cũ còn trong ô nháp.' );
	}
	return array( 'warn', 'Không rõ chị muốn làm gì. Chị thử lại nhé.' );
}

/*
 * The request.
 */

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['mp_hoidap'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification
	if ( 'manifest' === ( isset( $_GET['app'] ) ? sanitize_key( wp_unslash( $_GET['app'] ) ) : '' ) ) {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( array(
			'name'             => 'Mây Piano · Hỏi đáp',
			'short_name'       => 'Hỏi đáp',
			'start_url'        => maypiano_qna_review_url(),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#E3D7D7',
			'theme_color'      => '#E3D7D7',
			'lang'             => 'vi',
			'icons'            => array(
				array( 'src' => get_theme_file_uri( 'assets/img/icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png' ),
				array( 'src' => get_theme_file_uri( 'assets/img/icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png' ),
			),
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
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

	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'cho';
	$tab = in_array( $tab, array( 'cho', 'de-danh', 'da', 'cai-dat' ), true ) ? $tab : 'cho';
	$back = 'cho' === $tab ? array() : array( 'tab' => $tab );

	if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
		if ( isset( $_POST['mp_caidat'] ) || isset( $_POST['mp_thu'] ) ) {
			if ( ! check_admin_referer( 'mp_hoidap_caidat' ) ) {
				exit;
			}
			if ( isset( $_POST['mp_thu'] ) ) {
				$sent = maypiano_qna_send_digest( true );
				set_transient( 'mp_hoidap_note_' . $me->ID, $sent ? array( 'ok', 'Đã gửi email thử tới ' . implode( ', ', maypiano_qna_digest_to() ) . '.' ) : array( 'warn', 'Không gửi được email thử. Chị kiểm tra địa chỉ email nhé.' ), 120 );
			} else {
				$raw  = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
				$good = array_values( array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', $raw ) ), 'is_email' ) );
				$bad  = array_values( array_diff( array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', $raw ) ) ), $good ) );
				if ( ! $good ) {
					set_transient( 'mp_hoidap_note_' . $me->ID, array( 'warn', 'Chị điền ít nhất một email đúng nhé.' ), 120 );
				} else {
					update_option( 'maypiano_qna_mail_to', implode( ', ', $good ), false );
					update_option( 'maypiano_qna_mail_on', empty( $_POST['on'] ) ? '0' : '1', false );
					update_option( 'maypiano_qna_mail_hour', min( 23, max( 0, isset( $_POST['hour'] ) ? absint( $_POST['hour'] ) : 8 ) ), false );
					set_transient( 'mp_hoidap_note_' . $me->ID, $bad ? array( 'warn', 'Đã lưu. Bỏ qua địa chỉ không đúng: ' . implode( ', ', $bad ) ) : array( 'ok', 'Đã lưu cài đặt email.' ), 120 );
				}
			}
			wp_safe_redirect( maypiano_qna_review_url( array( 'tab' => 'cai-dat' ) ) );
			exit;
		}
		$id     = isset( $_POST['entry'] ) ? absint( $_POST['entry'] ) : 0;
		$entry  = $id ? get_post( $id ) : null;
		$answer = isset( $_POST['answer'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) ) : '';
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( ! $entry || MAYPIANO_QNA_TYPE !== $entry->post_type || ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '', 'mp_hoidap_' . $id ) ) {
			$note = array( 'warn', 'Trang đã cũ. Chị tải lại trang rồi bấm lần nữa nhé.' );
		} else {
			$note = maypiano_qna_act( $do, $entry, $answer, $me );
		}
		set_transient( 'mp_hoidap_note_' . $me->ID, $note, 120 );
		wp_safe_redirect( maypiano_qna_review_url( $back ) . ( $id && in_array( $do, array( 'luu', 'sua' ), true ) ? '#m' . $id : '' ) );
		exit;
	}

	$notice = '';
	$note   = get_transient( 'mp_hoidap_note_' . $me->ID );
	if ( is_array( $note ) ) {
		delete_transient( 'mp_hoidap_note_' . $me->ID );
		$notice = '<div class="' . ( 'ok' === $note[0] ? 'ok' : 'warn' ) . '" role="status">' . esc_html( $note[1] ) . '</div>';
	}

	$q      = isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : '';
	$course = isset( $_GET['khoa'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['khoa'] ) ) ) : '';
	$shown  = isset( $_GET['xem'] ) ? max( 20, absint( $_GET['xem'] ) ) : 20;
	// phpcs:enable

	$draft   = maypiano_qna_list( 'draft' );
	$pending = maypiano_qna_list( 'pending' );
	$later   = maypiano_qna_list( 'later' );
	$counts  = array( 'cho' => count( $draft ) + count( $pending ), 'de-danh' => count( $later ) );
	$tabs    = maypiano_qna_tabs( $tab, $counts );

	if ( 'cai-dat' === $tab ) {
		$s    = maypiano_qna_mail_settings();
		$hour = '';
		for ( $h = 5; $h <= 22; $h++ ) {
			$hour .= '<option value="' . $h . '"' . selected( $h, $s['hour'], false ) . '>' . $h . ' giờ sáng' . '</option>';
		}
		$hour = str_replace( array( '>12 giờ sáng', '>13 giờ sáng', '>14 giờ sáng', '>15 giờ sáng', '>16 giờ sáng', '>17 giờ sáng', '>18 giờ sáng', '>19 giờ sáng', '>20 giờ sáng', '>21 giờ sáng', '>22 giờ sáng' ), array( '>12 giờ trưa', '>1 giờ chiều', '>2 giờ chiều', '>3 giờ chiều', '>4 giờ chiều', '>5 giờ chiều', '>6 giờ tối', '>7 giờ tối', '>8 giờ tối', '>9 giờ tối', '>10 giờ tối' ), $hour );
		$nonce = wp_nonce_field( 'mp_hoidap_caidat', '_wpnonce', false, false );
		$body  = $notice
			. '<p class="lead">Mỗi ngày một email báo khi có câu hỏi chờ. Không có câu nào thì không gửi.</p>'
			. '<form class="set" method="post" action="' . esc_url( maypiano_qna_review_url( array( 'tab' => 'cai-dat' ) ) ) . '">' . $nonce
			. '<div class="f"><label class="switch">Gửi email báo mỗi ngày<input type="checkbox" name="on" value="1"' . checked( $s['on'], true, false ) . '></label></div>'
			. '<div class="f"><label for="mp-to">Gửi tới (nhiều email thì cách nhau dấu phẩy)</label><input id="mp-to" type="text" name="to" inputmode="email" autocomplete="off" value="' . esc_attr( implode( ', ', $s['to'] ) ) . '"></div>'
			. '<div class="f"><label for="mp-hour">Gửi lúc (giờ Melbourne)</label><select id="mp-hour" name="hour">' . $hour . '</select></div>'
			. '<button type="submit" name="mp_caidat" value="1">Lưu cài đặt</button></form>'
			. '<form method="post" action="' . esc_url( maypiano_qna_review_url( array( 'tab' => 'cai-dat' ) ) ) . '">' . $nonce
			. '<button class="ghost" type="submit" name="mp_thu" value="1">Gửi thử email báo ngay</button></form>'
			. '<h2>Cách trang này chạy</h2>'
			. '<p class="small">Bấm Đăng là câu trả lời hiện ngay dưới câu hỏi, tên Trợ lý May Piano. Câu hỏi chưa trả lời chỉ người hỏi và chị thấy. Đã trả lời rồi thì mọi học viên của khóa đều thấy.</p>'
			. '<p class="small">Để dành: giữ câu hỏi lại cho video giải đáp, không tính vào email báo. Ẩn: không ai thấy câu hỏi nữa, kể cả người hỏi.</p>'
			. '<p class="small">Trên iPhone, chị mở trang này bằng Safari, bấm nút Chia sẻ rồi chọn "Thêm vào MH chính" để có biểu tượng như một ứng dụng.</p>'
			. '<p class="small">Đang đăng nhập: ' . esc_html( $me->user_email ) . '</p>';
		maypiano_qna_review_page( 'Cài đặt', $body, 200, $tabs );
	}

	if ( 'da' === $tab ) {
		$all     = maypiano_qna_list( 'answered' );
		$hidden  = maypiano_qna_list( 'hidden' );
		$courses = array_values( array_unique( array_filter( array_map( function ( $e ) {
			return maypiano_qna_fields( $e )['course'];
		}, array_merge( $all, $hidden ) ) ) ) );
		$list    = maypiano_qna_filter( $all, $q, $course );
		$body    = $notice . maypiano_qna_tools( 'da', $q, $course, $courses );
		if ( ! $list ) {
			$body .= '<div class="empty"><strong>' . ( '' !== $q || '' !== $course ? 'Không thấy câu nào' : 'Chưa có câu nào' ) . '</strong>' . ( '' !== $q || '' !== $course ? 'Chị thử chữ khác nhé.' : 'Câu đã trả lời sẽ hiện ở đây.' ) . '</div>';
		} else {
			$body .= '<h2>' . count( $list ) . ' câu đã trả lời</h2>';
			foreach ( array_slice( $list, 0, $shown ) as $entry ) {
				$body .= maypiano_qna_answered_card( $entry );
			}
			if ( count( $list ) > $shown ) {
				$body .= '<a class="more" href="' . esc_url( maypiano_qna_review_url( array_filter( array( 'tab' => 'da', 'q' => $q, 'khoa' => $course, 'xem' => $shown + 20 ) ) ) ) . '">Xem thêm</a>';
			}
		}
		$hidden = maypiano_qna_filter( $hidden, $q, $course );
		if ( $hidden ) {
			$body .= '<h2>Đã ẩn (' . count( $hidden ) . ')</h2>';
			foreach ( $hidden as $entry ) {
				$body .= maypiano_qna_hidden_card( $entry );
			}
		}
		maypiano_qna_review_page( 'Đã trả lời', $body, 200, $tabs );
	}

	$courses = array_values( array_unique( array_filter( array_map( function ( $e ) {
		return maypiano_qna_fields( $e )['course'];
	}, array_merge( $draft, $pending, $later ) ) ) ) );

	if ( 'de-danh' === $tab ) {
		$list = maypiano_qna_filter( $later, $q, $course );
		$body = $notice . '<p class="lead">Câu hỏi chị giữ lại để trả lời trong video giải đáp. Đăng ở đây lúc nào cũng được.</p>';
		if ( $later ) {
			$body .= maypiano_qna_tools( 'de-danh', $q, $course, $courses );
		}
		if ( ! $list ) {
			$body .= '<div class="empty"><strong>' . ( $later ? 'Không thấy câu nào' : 'Chưa để dành câu nào' ) . '</strong>' . ( $later ? 'Chị thử chữ khác nhé.' : 'Bấm Để dành ở một câu hỏi để giữ nó ở đây.' ) . '</div>';
		}
		foreach ( $list as $entry ) {
			$body .= maypiano_qna_waiting_card( $entry, 'de-danh' );
		}
		maypiano_qna_review_page( 'Để dành', $body, 200, $tabs );
	}

	$d    = maypiano_qna_filter( $draft, $q, $course );
	$p    = maypiano_qna_filter( $pending, $q, $course );
	$body = $notice;
	if ( $counts['cho'] ) {
		$body .= maypiano_qna_tools( 'cho', $q, $course, $courses );
	}
	if ( ! $d && ! $p ) {
		$body .= $counts['cho']
			? '<div class="empty"><strong>Không thấy câu nào</strong>Chị thử chữ khác nhé.</div>'
			: '<div class="empty"><strong>Không có câu nào chờ</strong>Chị nghỉ ngơi nhé.</div>';
	}
	if ( $d ) {
		$body .= '<h2>Có nháp sẵn (' . count( $d ) . ')</h2>';
		foreach ( $d as $entry ) {
			$body .= maypiano_qna_waiting_card( $entry, 'cho' );
		}
	}
	if ( $p ) {
		$body .= '<h2>Chị trả lời (' . count( $p ) . ')</h2>';
		foreach ( $p as $entry ) {
			$body .= maypiano_qna_waiting_card( $entry, 'cho' );
		}
	}
	maypiano_qna_review_page( 'Chờ trả lời', $body, 200, $tabs );
}, 5 );

/*
 * The email.
 */

/**
 * The daily email: how many wait, and the first few questions. Sent once a day at the chosen hour (Melbourne time),
 * only when the email is on and something waits. $test sends it now, whatever the setting, even when nothing waits.
 */
function maypiano_qna_send_digest( $test = false ) {
	$s     = maypiano_qna_mail_settings();
	$draft = maypiano_qna_list( 'draft' );
	$pend  = maypiano_qna_list( 'pending' );
	$d     = count( $draft );
	$p     = count( $pend );
	if ( ! $s['to'] || ( ! $test && ( ! $s['on'] || 0 === $d + $p ) ) ) {
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
	$items = '';
	foreach ( array_slice( array_merge( $draft, $pend ), 0, 5 ) as $e ) {
		$f      = maypiano_qna_fields( $e );
		$items .= '<li style="margin:0 0 10px"><strong>' . esc_html( wp_html_excerpt( $f['question'], 140, '…' ) ) . '</strong><br><span style="font-size:14px;color:#7A6670">' . esc_html( $f['asker'] . ' · ' . $f['lesson'] ) . '</span></li>';
	}
	$total = $d + $p;
	$body  = '<p><strong>' . ( $total ? 'Hôm nay có ' . $total . ' câu hỏi chờ chị.' : 'Email thử.' ) . '</strong></p>'
		. '<p>' . implode( '<br>', array_map( 'esc_html', $lines ) ) . '</p>'
		. ( $items ? '<ul style="padding-left:18px;margin:14px 0">' . $items . '</ul>' . ( $total > 5 ? '<p style="font-size:14px;color:#7A6670">Và ' . ( $total - 5 ) . ' câu nữa.</p>' : '' ) : '' )
		. maypiano_mail_button( maypiano_qna_review_url(), 'Mở trang Hỏi đáp' )
		. '<p style="font-size:14px;color:#7A6670">Trang này cần đăng nhập bằng tài khoản của chị.</p>';
	return (bool) maypiano_mail( $s['to'], ( $test ? '[Thử] ' : '' ) . 'Mây Piano: ' . ( $total ? $total . ' câu hỏi chờ chị' : 'không có câu hỏi chờ' ), $body );
}

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'maypiano_qna_digest_hourly' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'maypiano_qna_digest_hourly' );
	}
} );

add_action( 'maypiano_qna_digest_hourly', function () {
	$s   = maypiano_qna_mail_settings();
	$now = new DateTime( 'now', new DateTimeZone( 'Australia/Melbourne' ) );
	$day = $now->format( 'Y-m-d' );
	if ( ! $s['on'] || (int) $now->format( 'G' ) < $s['hour'] || get_option( 'maypiano_qna_digest_day' ) === $day ) {
		return;
	}
	update_option( 'maypiano_qna_digest_day', $day, false );
	maypiano_qna_send_digest();
} );
