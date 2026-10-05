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
 * Entries are never public.
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

/** A lesson page hands its lesson to the questions page, so a question remembers which lesson it was asked from. */
add_action( 'wp_footer', function () {
	if ( ! is_singular( array( 'lesson', 'tutor_quiz', 'tutor_assignments' ) ) ) {
		return;
	}
	?>
<script>
(function () {
	var bai = <?php echo (int) get_queried_object_id(); ?>;
	document.querySelectorAll('a[href*="subpage=qna"]').forEach(function (a) {
		if (a.href.indexOf('bai=') < 0) { a.href += (a.href.indexOf('?') < 0 ? '?' : '&') + 'bai=' + bai; }
	});
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
	$from = array();
	wp_parse_str( (string) wp_parse_url( (string) wp_get_referer(), PHP_URL_QUERY ), $from );
	$lesson = (int) ( $from['bai'] ?? 0 );
	if ( $lesson && in_array( get_post_type( $lesson ), array( 'lesson', 'tutor_quiz', 'tutor_assignments' ), true ) ) {
		update_comment_meta( $qid, 'mp_lesson', $lesson );
	}
	maypiano_qna_make_entry( get_comment( $qid ) );
}, 20 );

/** Questions asked before the desk existed get their entries once per version of the theme. */
add_action( 'init', function () {
	if ( MAYPIANO_VERSION !== get_option( 'maypiano_qna_ran' ) ) {
		update_option( 'maypiano_qna_ran', MAYPIANO_VERSION, false );
		maypiano_qna_sync();
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
