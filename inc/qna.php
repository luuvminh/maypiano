<?php
/**
 * Questions and answers (Tutor LMS "Q&A") in a course.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The name shown beside a question or an answer: the person's own name, never the sign-in name or an email address. */
function maypiano_qna_name( $user_id ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		return 'Học viên';
	}
	$full = trim( $user->first_name . ' ' . $user->last_name );
	foreach ( array( $full, (string) $user->display_name ) as $name ) {
		$name = trim( $name );
		if ( '' !== $name && false === strpos( $name, '@' ) && 0 !== strcasecmp( $name, $user->user_login ) ) {
			return $name;
		}
	}
	return 'Học viên';
}

/** Tutor LMS stores the sign-in name with each question. Store the person's name instead. */
add_filter( 'tutor_qna_insert_data', function ( $data ) {
	if ( is_array( $data ) && ! empty( $data['user_id'] ) ) {
		$data['comment_author'] = maypiano_qna_name( $data['user_id'] );
	}
	return $data;
} );

/*
 * The answering desk.
 *
 * Each question a learner asks gets a private entry ("Câu hỏi chờ trả lời") that the site owner's tools can read
 * and write. The entry's status says where the question stands:
 *   pending = waiting for an answer
 *   draft   = an answer has been written into the entry's excerpt and waits for a yes
 *   private = answered; the excerpt, if any, has been posted under the question
 * Entries are never public. Each entry carries the address of its lesson's page ("Link bài"), where the question and its
 * answers are read under the video.
 */

const MAYPIANO_QNA_TYPE   = 'mp_hoi';
const MAYPIANO_QNA_HELPER = 'Trợ lý May Piano';

add_action( 'init', function () {
	register_post_type( MAYPIANO_QNA_TYPE, array(
		'label'               => 'Câu hỏi chờ trả lời',
		'public'              => false,
		'show_ui'             => true,
		'show_in_rest'        => true,
		'rest_base'           => MAYPIANO_QNA_TYPE,
		'exclude_from_search' => true,
		'supports'            => array( 'title', 'editor', 'excerpt' ),
		'capability_type'     => 'page',
		'menu_icon'           => 'dashicons-format-chat',
	) );
} );

/** An entry can never be published: anything published would be readable by anyone. */
add_filter( 'wp_insert_post_data', function ( $data ) {
	if ( MAYPIANO_QNA_TYPE === ( $data['post_type'] ?? '' ) && in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ) {
		$data['post_status'] = 'private';
	}
	return $data;
} );

/** The account answers are posted from: the teacher's, because Tutor LMS only shows answers that belong to an account. */
function maypiano_qna_helper_id() {
	$user = get_user_by( 'email', trim( (string) get_option( 'maypiano_teacher_email', '' ) ) );
	if ( $user ) {
		return (int) $user->ID;
	}
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	return $admins ? (int) $admins[0] : 0;
}

/** The entry of a question, or 0. */
function maypiano_qna_entry_of( $question_id ) {
	return (int) get_comment_meta( (int) $question_id, 'mp_entry', true );
}

/**
 * Where a question is read on the site: its lesson's page, with the questions box under the video open.
 * A question without a lesson opens on the course's questions page instead.
 */
function maypiano_qna_link( $question ) {
	$qid    = (int) $question->comment_ID;
	$lesson = (int) get_comment_meta( $qid, 'mp_lesson', true );
	if ( $lesson && 'publish' === get_post_status( $lesson ) ) {
		return get_permalink( $lesson ) . '#mp-hoi-dap';
	}
	return add_query_arg( array( 'subpage' => 'qna', 'question_id' => $qid ), get_permalink( (int) $question->comment_post_ID ) );
}

/** Entries made before they carried the link get the line, right under the lesson's name. Nothing else in the entry changes. */
function maypiano_qna_add_links() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = %s AND post_content NOT LIKE %s LIMIT 500", MAYPIANO_QNA_TYPE, '%Link bài: %' ) ); // phpcs:ignore WordPress.DB
	foreach ( (array) $rows as $row ) {
		$question = get_comment( (int) get_post_meta( (int) $row->ID, '_mp_question', true ) );
		if ( ! $question ) {
			continue;
		}
		$line  = esc_html( 'Link bài: ' . maypiano_qna_link( $question ) );
		$count = 0;
		$text  = preg_replace_callback( '/^Bài học: .*$/mu', function ( $m ) use ( $line ) {
			return $m[0] . "\n" . $line;
		}, (string) $row->post_content, 1, $count );
		if ( ! $count ) {
			$text = $line . "\n" . $row->post_content;
		}
		// Written straight to the table: saving the entry the usual way would run the step that posts answers.
		$wpdb->update( $wpdb->posts, array( 'post_content' => $text ), array( 'ID' => (int) $row->ID ) ); // phpcs:ignore WordPress.DB
		clean_post_cache( (int) $row->ID );
	}
}

/** Makes the entry for one question, unless it has one. Returns the entry's ID or 0. */
function maypiano_qna_make_entry( $question ) {
	if ( ! $question || 'tutor_q_and_a' !== $question->comment_type || (int) $question->comment_parent ) {
		return 0;
	}
	$qid  = (int) $question->comment_ID;
	$have = maypiano_qna_entry_of( $qid );
	if ( $have && get_post( $have ) ) {
		return $have;
	}
	global $wpdb;
	$text     = trim( wp_strip_all_tags( (string) $question->comment_content ) );
	$name     = maypiano_qna_name( $question->user_id );
	$course   = get_the_title( (int) $question->comment_post_ID );
	$lesson   = (int) get_comment_meta( $qid, 'mp_lesson', true );
	$answered = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'tutor_q_and_a' AND comment_parent = %d AND user_id <> %d", $qid, (int) $question->user_id ) ); // phpcs:ignore WordPress.DB
	$lines    = array(
		'Mã câu hỏi: ' . $qid,
		'Khóa học: ' . $course,
		'Bài học: ' . ( $lesson ? get_the_title( $lesson ) : '(không rõ)' ),
		'Link bài: ' . maypiano_qna_link( $question ),
		'Người hỏi: ' . $name,
		'Ngày hỏi: ' . $question->comment_date,
		'',
		'Câu hỏi:',
		$text,
	);
	$entry = wp_insert_post( array(
		'post_type'    => MAYPIANO_QNA_TYPE,
		'post_status'  => $answered ? 'private' : 'pending',
		'post_title'   => wp_html_excerpt( $name . ': ' . $text, 90, '…' ),
		'post_content' => implode( "\n", array_map( 'esc_html', $lines ) ),
	) );
	if ( ! $entry || is_wp_error( $entry ) ) {
		return 0;
	}
	update_post_meta( $entry, '_mp_question', $qid );
	update_comment_meta( $qid, 'mp_entry', (int) $entry );
	return (int) $entry;
}

/** Every question without an entry gets one. */
function maypiano_qna_sync() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT c.comment_ID FROM {$wpdb->comments} c WHERE c.comment_type = 'tutor_q_and_a' AND c.comment_parent = 0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id = c.comment_ID AND m.meta_key = 'mp_entry') ORDER BY c.comment_ID ASC LIMIT 200" ); // phpcs:ignore WordPress.DB
	foreach ( (array) $ids as $id ) {
		maypiano_qna_make_entry( get_comment( (int) $id ) );
	}
}

/*
 * Questions by lesson.
 *
 * Tutor LMS keeps questions per course. Here the questions page opens on the questions of the lesson the learner came from,
 * with a switch to all questions of the course. In the address: bai = the lesson, xem=tat-ca = show all.
 */

const MAYPIANO_QNA_LESSON_TYPES = array( 'lesson', 'tutor_quiz', 'tutor_assignments' );

/** The lesson the questions page is about: the lesson being viewed, or the one named in the address. 0 when there is none. */
function maypiano_qna_lesson() {
	static $found = null;
	if ( null !== $found ) {
		return $found;
	}
	$found = 0;
	if ( is_singular( MAYPIANO_QNA_LESSON_TYPES ) ) {
		$found = (int) get_queried_object_id();
		return $found;
	}
	$id = isset( $_GET['bai'] ) ? absint( wp_unslash( $_GET['bai'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $id || ! in_array( get_post_type( $id ), MAYPIANO_QNA_LESSON_TYPES, true ) || 'publish' !== get_post_status( $id ) ) {
		return $found;
	}
	// The lesson must belong to the course on screen.
	if ( function_exists( 'tutor_utils' ) && is_singular() && (int) tutor_utils()->get_course_id_by_subcontent( $id ) !== (int) get_queried_object_id() ) {
		return $found;
	}
	$found = $id;
	return $found;
}

/** True when the learner asked to see every question of the course. */
function maypiano_qna_all() {
	return 'tat-ca' === ( isset( $_GET['xem'] ) ? sanitize_key( wp_unslash( $_GET['xem'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
}

/** The questions page goes through the theme (see maypiano_qna_page), and its menu link carries the lesson. */
add_filter( 'tutor_learning_area_sub_page_menu_items', function ( $items ) {
	if ( ! is_array( $items ) || empty( $items['qna'] ) ) {
		return $items;
	}
	$items['qna']['template'] = get_theme_file_path( 'inc/qna-page.php' );
	$lesson                   = maypiano_qna_lesson();
	if ( $lesson && ! empty( $items['qna']['url'] ) ) {
		$items['qna']['url'] = add_query_arg( 'bai', $lesson, $items['qna']['url'] );
	}
	return $items;
}, 20 );

/** Draws the questions page: Tutor LMS's own page, narrowed to one lesson, with the switch on top. */
function maypiano_qna_page() {
	global $tutor_course_id, $wpdb;
	$original = function_exists( 'tutor_get_template' ) ? tutor_get_template( 'learning-area.subpages.qna' ) : '';
	if ( ! $original || ! file_exists( $original ) ) {
		return;
	}
	$single = ! empty( $_GET['question_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$lesson = maypiano_qna_lesson();
	$all    = maypiano_qna_all();
	$narrow = $lesson && ! $all && ! $single;

	// Tutor LMS has no way to ask for one lesson's questions, so its own two queries (the count and the list) get one more condition.
	$only = function ( $sql ) use ( $wpdb, $lesson ) {
		if ( false === strpos( $sql, "_question.comment_type = 'tutor_q_and_a'" ) || false === strpos( $sql, '_question.comment_parent = 0' ) ) {
			return $sql;
		}
		$where = $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->commentmeta} mp_bai WHERE mp_bai.comment_id = _question.comment_ID AND mp_bai.meta_key = 'mp_lesson' AND mp_bai.meta_value = %s) ", (string) $lesson ); // phpcs:ignore WordPress.DB
		return preg_replace_callback( '/\sORDER BY _question\.comment_ID\s/', function ( $m ) use ( $where ) {
			return $where . $m[0];
		}, $sql, 1 );
	};

	$GLOBALS['maypiano_qna_labels'] = ! $narrow && ! $single;
	if ( $narrow ) {
		add_filter( 'query', $only );
	}
	ob_start();
	include $original;
	$html = (string) ob_get_clean();
	remove_filter( 'query', $only );
	$GLOBALS['maypiano_qna_labels'] = false;

	if ( $lesson && ! $single ) {
		$base = add_query_arg( array( 'subpage' => 'qna', 'bai' => $lesson ), get_permalink( (int) $tutor_course_id ) );
		$bar  = '<div class="mp-qna-scope">'
			. '<p class="mp-qna-scope-bai">Bài: <a href="' . esc_url( get_permalink( $lesson ) ) . '">' . esc_html( get_the_title( $lesson ) ) . '</a></p>'
			. '<div class="mp-qna-tabs" role="group" aria-label="Chọn câu hỏi muốn xem">'
			. '<a class="mp-qna-tab' . ( $all ? '' : ' is-on' ) . '" href="' . esc_url( $base ) . '"' . ( $all ? '' : ' aria-current="true"' ) . '>Câu hỏi của bài này</a>'
			. '<a class="mp-qna-tab' . ( $all ? ' is-on' : '' ) . '" href="' . esc_url( add_query_arg( 'xem', 'tat-ca', $base ) ) . '"' . ( $all ? ' aria-current="true"' : '' ) . '>Xem tất cả</a>'
			. '</div></div>';
		$spot = strpos( $html, '<div class="tutor-learning-area-qna"' );
		$html = false === $spot ? $bar . $html : substr_replace( $html, $bar, $spot, 0 );
		if ( $narrow && empty( $_GET['search'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$html = str_replace( esc_html__( 'No Questions Found!', 'tutor' ), 'Bài này chưa có câu hỏi nào.', $html );
		}
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- Tutor LMS's own page, already escaped there.
}

/** When all questions are listed, each one says which lesson it was asked from. */
add_action( 'tutor_load_template_before', function ( $template ) {
	if ( 'learning-area.subpages.qna.card' === $template && ! empty( $GLOBALS['maypiano_qna_labels'] ) ) {
		ob_start();
	}
}, 10, 1 );
add_action( 'tutor_load_template_after', function ( $template, $variables ) {
	if ( 'learning-area.subpages.qna.card' !== $template || empty( $GLOBALS['maypiano_qna_labels'] ) ) {
		return;
	}
	$html     = (string) ob_get_clean();
	$question = is_array( $variables ) ? ( $variables['question'] ?? null ) : null;
	$lesson   = $question ? (int) get_comment_meta( (int) $question->comment_ID, 'mp_lesson', true ) : 0;
	if ( $lesson && 'publish' === get_post_status( $lesson ) ) {
		$label = '<a class="mp-qna-bai" href="' . esc_url( get_permalink( $lesson ) ) . '">Bài: ' . esc_html( get_the_title( $lesson ) ) . '</a>';
		$html  = preg_replace_callback( '/<a\s[^>]*class="tutor-discussion-card-title"/', function ( $m ) use ( $label ) {
			return $label . $m[0];
		}, $html, 1 );
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- Tutor LMS's own card, already escaped there.
}, 10, 2 );

/*
 * The questions box under the video.
 *
 * On a lesson page, right under the Previous and Next buttons: a box the learner opens and closes, with the place to ask
 * and the newest questions of this lesson together with their answers.
 */

const MAYPIANO_QNA_BOX_SHOWN = 5;

/** The newest questions of one lesson with their answers, and how many questions the lesson has. */
function maypiano_qna_of_lesson( $course, $lesson, $limit ) {
	global $wpdb;
	$from = "FROM {$wpdb->comments} c INNER JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = 'mp_lesson' AND m.meta_value = %s
		WHERE c.comment_type = 'tutor_q_and_a' AND c.comment_parent = 0 AND c.comment_post_ID = %d
		AND NOT EXISTS (SELECT 1 FROM {$wpdb->commentmeta} a WHERE a.comment_id = c.comment_ID AND a.meta_key = 'tutor_qna_archived' AND a.meta_value = '1')";
	$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", (string) $lesson, (int) $course ) ); // phpcs:ignore WordPress.DB
	$questions = $total ? $wpdb->get_results( $wpdb->prepare( "SELECT c.comment_ID, c.comment_author, c.comment_date_gmt, c.comment_content, c.user_id {$from} ORDER BY c.comment_ID DESC LIMIT %d", (string) $lesson, (int) $course, (int) $limit ) ) : array(); // phpcs:ignore WordPress.DB
	$answers   = array();
	if ( $questions ) {
		$ids  = implode( ',', array_map( 'intval', wp_list_pluck( $questions, 'comment_ID' ) ) );
		$rows = $wpdb->get_results( "SELECT comment_ID, comment_parent, comment_author, comment_date_gmt, comment_content, user_id FROM {$wpdb->comments} WHERE comment_type = 'tutor_q_and_a' AND comment_parent IN ({$ids}) ORDER BY comment_ID ASC" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$answers[ (int) $row->comment_parent ][] = $row;
		}
	}
	return array( $total, $questions, $answers );
}

/** "3 phút trước". */
function maypiano_qna_ago( $gmt ) {
	/* translators: %s: a length of time. */
	return sprintf( __( '%s ago', 'tutor' ), human_time_diff( strtotime( $gmt . ' UTC' ) ) );
}

add_action( 'tutor_load_template_after', function ( $template ) {
	global $tutor_course_id;
	if ( 'learning-area.lesson.footer' !== $template || ! is_singular( 'lesson' ) || ! class_exists( '\TUTOR\Template' ) ) {
		return;
	}
	$lesson = (int) get_queried_object_id();
	$course = (int) $tutor_course_id;
	$menu   = $course ? \TUTOR\Template::make_learning_area_sub_page_nav_items( get_permalink( $course ) ) : array();
	if ( ! $lesson || empty( $menu['qna'] ) ) {
		return; // Questions are off for this course, or this visitor may not ask.
	}
	list( $total, $questions, $answers ) = maypiano_qna_of_lesson( $course, $lesson, MAYPIANO_QNA_BOX_SHOWN );
	$page = add_query_arg( array( 'subpage' => 'qna', 'bai' => $lesson ), get_permalink( $course ) );
	?>
<details class="mp-qna-box" id="mp-hoi-dap">
	<summary>
		<span class="mp-qna-box-title">Hỏi đáp về bài này</span>
		<span class="mp-qna-box-count"><?php echo $total ? esc_html( $total . ' câu hỏi' ) : 'Chưa có câu hỏi'; ?></span>
		<span class="mp-qna-box-arrow" aria-hidden="true"></span>
	</summary>
	<div class="mp-qna-box-body">
		<?php // The theme's own form: Tutor LMS only loads its question form's script on its questions page, not on a lesson. ?>
		<form class="mp-qna-ask" data-course="<?php echo (int) $course; ?>" data-bai="<?php echo (int) $lesson; ?>">
			<label class="mp-qna-box-lead" for="mp-qna-ask-text">Bạn có chỗ nào chưa rõ trong bài này thì viết câu hỏi ở đây nhé.</label>
			<textarea id="mp-qna-ask-text" name="answer" rows="3" maxlength="2000" placeholder="<?php echo esc_attr__( 'Asked questions...', 'tutor' ); ?>"></textarea>
			<div class="mp-qna-ask-row">
				<p class="mp-qna-ask-note" role="status" aria-live="polite"></p>
				<button type="submit" class="mp-qna-send">Gửi câu hỏi</button>
			</div>
		</form>
		<?php if ( $questions ) : ?>
		<ul class="mp-qna-list">
			<?php foreach ( $questions as $q ) : ?>
			<li class="mp-qna-item">
				<p class="mp-qna-who"><strong><?php echo esc_html( $q->comment_author ); ?></strong> <span><?php echo esc_html( maypiano_qna_ago( $q->comment_date_gmt ) ); ?></span></p>
				<div class="mp-qna-text"><?php echo wp_kses_post( wpautop( $q->comment_content ) ); ?></div>
				<?php foreach ( $answers[ (int) $q->comment_ID ] ?? array() as $a ) : ?>
				<div class="mp-qna-answer">
					<p class="mp-qna-who"><strong><?php echo esc_html( $a->comment_author ); ?></strong> <span><?php echo esc_html( maypiano_qna_ago( $a->comment_date_gmt ) ); ?></span></p>
					<div class="mp-qna-text"><?php echo wp_kses_post( wpautop( $a->comment_content ) ); ?></div>
				</div>
				<?php endforeach; ?>
				<a class="mp-qna-reply" href="<?php echo esc_url( add_query_arg( 'question_id', (int) $q->comment_ID, $page ) ); ?>">Trả lời</a>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<p class="mp-qna-more">
			<?php if ( $total > count( $questions ) ) : ?>
			<a href="<?php echo esc_url( $page ); ?>">Xem cả <?php echo (int) $total; ?> câu hỏi của bài này</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( add_query_arg( 'xem', 'tat-ca', $page ) ); ?>">Xem câu hỏi của cả khóa</a>
		</p>
	</div>
</details>
<script>
(function () {
	var box = document.getElementById('mp-hoi-dap'), key = 'mp-hoi-dap';
	if (!box) { return; }
	try { if (localStorage.getItem(key) === '1' || location.hash === '#mp-hoi-dap') { box.open = true; } } catch (e) {}
	box.addEventListener('toggle', function () { try { localStorage.setItem(key, box.open ? '1' : '0'); } catch (e) {} });

	var form = box.querySelector('.mp-qna-ask');
	if (!form) { return; }
	var text = form.querySelector('textarea'), note = form.querySelector('.mp-qna-ask-note'), send = form.querySelector('.mp-qna-send');
	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var t = window._tutorobject || {}, words = text.value.trim();
		if (!words) { note.textContent = 'Bạn viết câu hỏi trước rồi bấm gửi nhé.'; text.focus(); return; }
		if (!t.ajaxurl || !t.nonce_key) { note.textContent = 'Chưa gửi được. Bạn tải lại trang rồi thử lại nhé.'; return; }
		var body = new FormData();
		body.append('action', 'tutor_qna_create_update');
		body.append('course_id', form.dataset.course);
		body.append('mp_bai', form.dataset.bai);
		body.append('answer', words);
		body.append(t.nonce_key, t[t.nonce_key]);
		send.disabled = true; send.textContent = 'Đang gửi...'; note.textContent = '';
		fetch(t.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (r) {
				if (!r || !r.success) { throw new Error((r && r.data && r.data.message) || ''); }
				try { localStorage.setItem(key, '1'); } catch (e) {}
				location.reload();
			})
			.catch(function () {
				send.disabled = false; send.textContent = 'Gửi câu hỏi';
				note.textContent = 'Chưa gửi được câu hỏi. Bạn thử lại nhé.';
			});
	});
	text.addEventListener('keydown', function (ev) {
		if ((ev.metaKey || ev.ctrlKey) && ev.key === 'Enter') { form.requestSubmit(); }
	});
})();
</script>
	<?php
}, 20, 1 );

/** Links and the search box on these pages keep the lesson, so the learner stays on the same lesson's questions and a new question remembers its lesson. */
add_action( 'wp_footer', function () {
	$lesson = maypiano_qna_lesson();
	if ( ! $lesson ) {
		return;
	}
	?>
<script>
(function () {
	var keep = { bai: '<?php echo (int) $lesson; ?>'<?php echo maypiano_qna_all() ? ", xem: 'tat-ca'" : ''; ?> };
	document.querySelectorAll('a[href*="subpage=qna"]:not(.mp-qna-tab)').forEach(function (a) {
		var u;
		try { u = new URL(a.href, location.href); } catch (e) { return; }
		Object.keys(keep).forEach(function (k) { if (!u.searchParams.has(k)) { u.searchParams.set(k, keep[k]); } });
		a.href = u.toString();
	});
	var f = document.getElementById('tutor-qna-search-form');
	if (f) {
		Object.keys(keep).forEach(function (k) {
			if (f.querySelector('input[name="' + k + '"]')) { return; }
			var i = document.createElement('input');
			i.type = 'hidden'; i.name = k; i.value = keep[k];
			f.appendChild(i);
		});
	}
})();
</script>
	<?php
}, 99 );

/** After a learner asks or someone answers: note the lesson, make the entry, and close the entry when a person has answered. */
add_action( 'tutor_after_asked_question', function ( $data ) {
	global $wpdb;
	if ( ! is_array( $data ) ) {
		return;
	}
	$parent = (int) ( $data['comment_parent'] ?? 0 );
	if ( $parent ) {
		$question = get_comment( $parent );
		$entry    = $question ? maypiano_qna_entry_of( $parent ) : 0;
		// An answer from anyone but the asker settles the question.
		if ( $entry && $question && (int) $question->user_id !== (int) ( $data['user_id'] ?? 0 ) && 'private' !== get_post_status( $entry ) ) {
			update_post_meta( $entry, '_mp_posted', 'person' );
			wp_update_post( array( 'ID' => $entry, 'post_status' => 'private' ) );
		}
		return;
	}
	$qid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = 'tutor_q_and_a' AND comment_parent = 0 AND user_id = %d ORDER BY comment_ID DESC LIMIT 1", (int) ( $data['user_id'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB
	if ( ! $qid ) {
		return;
	}
	// The lesson comes with the question (the box under the video), or from the address of the page it was asked on.
	$lesson = isset( $_POST['mp_bai'] ) ? absint( wp_unslash( $_POST['mp_bai'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- Tutor LMS has checked it.
	if ( ! $lesson ) {
		$from = array();
		wp_parse_str( (string) wp_parse_url( (string) wp_get_referer(), PHP_URL_QUERY ), $from );
		$lesson = (int) ( $from['bai'] ?? 0 );
	}
	if ( ! $lesson ) {
		$lesson = (int) url_to_postid( (string) wp_get_referer() );
	}
	$question_now = get_comment( $qid );
	if ( $lesson && function_exists( 'tutor_utils' ) && $question_now && (int) tutor_utils()->get_course_id_by_subcontent( $lesson ) !== (int) $question_now->comment_post_ID ) {
		$lesson = 0;
	}
	if ( $lesson && in_array( get_post_type( $lesson ), MAYPIANO_QNA_LESSON_TYPES, true ) ) {
		update_comment_meta( $qid, 'mp_lesson', $lesson );
	}
	maypiano_qna_make_entry( get_comment( $qid ) );
}, 20 );

/** Questions asked before the desk existed get their entries once per version of the theme. */
add_action( 'init', function () {
	if ( MAYPIANO_VERSION !== get_option( 'maypiano_qna_ran' ) ) {
		update_option( 'maypiano_qna_ran', MAYPIANO_VERSION, false );
		maypiano_qna_sync();
		maypiano_qna_add_links();
	}
}, 30 );

/** An entry marked answered with an answer in its excerpt: the answer is posted under the question, once. */
add_action( 'save_post_' . MAYPIANO_QNA_TYPE, function ( $entry, $post ) {
	global $wpdb;
	if ( 'private' !== $post->post_status || get_post_meta( $entry, '_mp_posted', true ) ) {
		return;
	}
	$answer   = trim( (string) $post->post_excerpt );
	$qid      = (int) get_post_meta( $entry, '_mp_question', true );
	$question = $qid ? get_comment( $qid ) : null;
	$helper   = maypiano_qna_helper_id();
	if ( '' === $answer || ! $question || 'tutor_q_and_a' !== $question->comment_type || ! $helper ) {
		return;
	}
	update_post_meta( $entry, '_mp_posted', 'pending' );
	$now = gmdate( 'Y-m-d H:i:s', function_exists( 'tutor_time' ) ? tutor_time() : time() );
	$wpdb->insert( $wpdb->comments, array( // phpcs:ignore WordPress.DB
		'comment_post_ID'  => (int) $question->comment_post_ID,
		'comment_author'   => MAYPIANO_QNA_HELPER,
		'comment_date'     => $now,
		'comment_date_gmt' => get_gmt_from_date( $now ),
		'comment_content'  => wp_kses_post( wpautop( esc_html( $answer ) ) ),
		'comment_approved' => 'approved',
		'comment_agent'    => 'TutorLMSPlugin',
		'comment_type'     => 'tutor_q_and_a',
		'comment_parent'   => $qid,
		'user_id'          => $helper,
	) );
	$posted = (int) $wpdb->insert_id;
	if ( ! $posted ) {
		delete_post_meta( $entry, '_mp_posted' );
		return;
	}
	update_post_meta( $entry, '_mp_posted', $posted );
	update_comment_meta( $posted, 'mp_helper', 1 );
	// The asker sees the question as having something new.
	update_comment_meta( $qid, 'tutor_qna_read_' . (int) $question->user_id, 0 );
	clean_comment_cache( array( $qid, $posted ) );
}, 10, 2 );

/**
 * A course without a picture of its own in the site's library uses the photo named in its file in data/,
 * so the learner's pages never show the grey stand-in picture.
 */
add_filter( 'tutor_course_thumbnail_placeholder', function ( $url, $post_id = 0 ) {
	$post_id = (int) $post_id;
	if ( $post_id && 'courses' !== get_post_type( $post_id ) && function_exists( 'tutor_utils' ) ) {
		$post_id = (int) tutor_utils()->get_course_id_by_lesson( $post_id );
	}
	$slug  = $post_id ? (string) get_post_field( 'post_name', $post_id ) : '';
	$file  = '' !== $slug ? get_theme_file_path( 'data/' . sanitize_file_name( $slug ) . '.json' ) : '';
	$data  = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$photo = is_array( $data ) && isset( $data['photo'] ) ? sanitize_file_name( $data['photo'] ) : 'g04.jpg';
	return get_theme_file_uri( 'assets/img/' . $photo );
}, 10, 2 );

/**
 * "Hỏi đáp của mình" in the learner's pages. Tutor LMS shows the course's name as a card that opens on hover and leads nowhere.
 * Here it becomes a plain link straight to the lesson the question was asked from (its Hỏi đáp box open), named after the lesson.
 */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'tutor_utils' ) || ! is_page( (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) ) ) {
		return;
	}
	$mine = get_comments( array(
		'user_id' => get_current_user_id(),
		'type'    => 'tutor_q_and_a',
		'parent'  => 0,
		'status'  => 'any',
		'number'  => 300,
	) );
	$one  = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $one ) {
		$q = get_comment( $one );
		// Somebody else's question is linked only for a person who may see it: its asker, or whoever teaches the course.
		if ( $q && 'tutor_q_and_a' === $q->comment_type && ( (int) $q->user_id === get_current_user_id() || current_user_can( 'edit_post', (int) $q->comment_post_ID ) ) ) {
			$mine[] = $q;
		}
	}
	$map = array();
	foreach ( $mine as $q ) {
		$lesson = (int) get_comment_meta( (int) $q->comment_ID, 'mp_lesson', true );
		$known  = $lesson && 'publish' === get_post_status( $lesson );
		$map[ (int) $q->comment_ID ] = array(
			'url'  => esc_url_raw( maypiano_qna_link( $q ) ),
			'text' => $known ? 'Bài: ' . get_the_title( $lesson ) : 'Khóa: ' . get_the_title( (int) $q->comment_post_ID ),
			// Every question of the course, on the course's own Hỏi đáp page.
			'all'  => esc_url_raw( add_query_arg( array( 'subpage' => 'qna', 'xem' => 'tat-ca' ), get_permalink( (int) $q->comment_post_ID ) ) ),
		);
	}
	?>
<script>
(function () {
	var map = <?php echo wp_json_encode( $map ); ?>, one = <?php echo (int) $one; ?>;
	function idOf(trigger) {
		for (var el = trigger.parentNode; el && el !== document.body; el = el.parentNode) {
			var t = el.querySelector('[id^="tutor-qna-text-"]');
			if (t) return parseInt(t.id.replace('tutor-qna-text-', ''), 10);
			if (el.classList && el.classList.contains('tutor-discussion-single-body')) return one;
		}
		return 0;
	}
	function fix() {
		/* "Đi đến tất cả Hỏi đáp" opens the course's Hỏi đáp page, in the same tab. */
		if (map[one]) document.querySelectorAll('.tutor-dashboard-discussions a[href*="page_tab=qna"]').forEach(function (a) { a.href = map[one].all; a.removeAttribute('target'); });
		document.querySelectorAll('.tutor-dashboard-discussions .tutor-preview-trigger').forEach(function (trigger) {
			var hit = map[idOf(trigger)];
			if (!hit) return;
			var a = document.createElement('a');
			a.className = 'mp-qna-bai';
			a.href = hit.url;
			a.textContent = hit.text;
			var holder = trigger.parentNode, said = holder.previousElementSibling;
			holder.replaceChild(a, trigger);
			if (said && /asked in|đã hỏi trong/i.test(said.textContent)) said.remove();
		});
	}
	fix();
	document.addEventListener('DOMContentLoaded', fix);
	new MutationObserver(fix).observe(document.body, { childList: true, subtree: true });
})();
</script>
	<?php
}, 98 );

