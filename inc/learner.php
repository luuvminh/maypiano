<?php
/**
 * The learner's own pages (Tutor LMS draws them): whole courses and songs bought one by one are kept apart.
 *
 * A song sold on its own is a small Tutor LMS course (see inc/songs.php), so Tutor LMS lists it among the learner's
 * courses. Here the lists are narrowed: "Học tiếp", the numbers on the first page and the Khóa học page hold whole
 * courses only, and songs get a list of their own, each card saying it is a song.
 * The "Lượt làm bài kiểm tra" tab is taken away: the courses have no tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_SONG_LABEL = 'Bài hát mua riêng';

/** What the lists of "my courses" hold right now: 'khoa' (whole courses), 'bai-hat' (songs), or '' for both. */
function maypiano_learner_kind( $set = null ) {
	static $kind = '';
	if ( null !== $set ) {
		$kind = (string) $set;
	}
	return $kind;
}

/** Tutor LMS's three lists (enrolled, in progress, finished) pass through here. */
function maypiano_learner_narrow( $args ) {
	$kind = maypiano_learner_kind();
	if ( '' === $kind ) {
		return $args;
	}
	$args                 = (array) $args;
	$meta                 = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
	$meta[]               = array(
		'key'     => '_maypiano_song',
		'compare' => 'bai-hat' === $kind ? 'EXISTS' : 'NOT EXISTS',
	);
	$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
	return $args;
}
add_filter( 'tutor_get_enrolled_courses_by_user', 'maypiano_learner_narrow' );
add_filter( 'tutor_get_active_courses_by_user', 'maypiano_learner_narrow' );
add_filter( 'tutor_get_completed_courses_by_user', 'maypiano_learner_narrow' );

/** The signed-in learner's courses of one kind: a query holding up to $limit of them, or null when there are none. */
function maypiano_learner_list( $kind, $limit ) {
	if ( ! is_user_logged_in() || ! class_exists( '\Tutor\Models\CourseModel' ) ) {
		return null;
	}
	$before = maypiano_learner_kind();
	maypiano_learner_kind( $kind );
	$found = \Tutor\Models\CourseModel::get_enrolled_courses_by_user( get_current_user_id(), array( 'private', 'publish' ), 0, $limit );
	maypiano_learner_kind( $before );
	return ( $found instanceof WP_Query && $found->found_posts ) ? $found : null;
}

/** How many courses of one kind the signed-in learner has. */
function maypiano_learner_count( $kind ) {
	static $known = array();
	if ( ! isset( $known[ $kind ] ) ) {
		$list           = maypiano_learner_list( $kind, 1 );
		$known[ $kind ] = $list ? (int) $list->found_posts : 0;
	}
	return $known[ $kind ];
}

/** The Khóa học page of the learner's pages shows songs when its address says loai=bai-hat. */
function maypiano_learner_wants_songs() {
	return 'bai-hat' === ( isset( $_GET['loai'] ) ? sanitize_key( wp_unslash( $_GET['loai'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
}

function maypiano_learner_courses_url( $songs = false ) {
	$url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url( 'courses' ) : home_url( '/' );
	return $songs ? add_query_arg( 'loai', 'bai-hat', $url ) : $url;
}

const MAYPIANO_LEARNER_COURSE_PAGES = array( 'dashboard.courses', 'dashboard.courses/active-courses', 'dashboard.courses/completed-courses', 'dashboard.courses/wishlist', 'dashboard.courses/my-quiz-attempts' );

add_action( 'tutor_load_template_before', function ( $template ) {
	// First page: the numbers and "Học tiếp" are about whole courses.
	if ( 'dashboard.student.stats' === $template || 'dashboard.student.continue-learning' === $template ) {
		maypiano_learner_kind( 'khoa' );
		// Someone who only ever bought songs has no course to go on with: that block is left out for them.
		if ( 'dashboard.student.continue-learning' === $template && ! maypiano_learner_count( 'khoa' ) && maypiano_learner_count( 'bai-hat' ) ) {
			$GLOBALS['maypiano_learner_skip'] = true;
			ob_start();
		}
		return;
	}
	if ( in_array( $template, MAYPIANO_LEARNER_COURSE_PAGES, true ) ) {
		maypiano_learner_kind( maypiano_learner_wants_songs() ? 'bai-hat' : 'khoa' );
		ob_start();
		return;
	}
	// A song's card says so, above its name.
	if ( 'dashboard.courses.course-card-header' === $template && '' !== (string) get_post_meta( get_the_ID(), '_maypiano_song', true ) ) {
		$GLOBALS['maypiano_learner_card'] = true;
		ob_start();
	}
}, 5, 1 );

add_action( 'tutor_load_template_after', function ( $template ) {
	if ( 'dashboard.student.stats' === $template || 'dashboard.student.continue-learning' === $template ) {
		maypiano_learner_kind( '' );
		if ( ! empty( $GLOBALS['maypiano_learner_skip'] ) ) {
			$GLOBALS['maypiano_learner_skip'] = false;
			ob_end_clean();
		}
		return;
	}
	if ( 'dashboard.courses.course-card-header' === $template && ! empty( $GLOBALS['maypiano_learner_card'] ) ) {
		$GLOBALS['maypiano_learner_card'] = false;
		$html  = (string) ob_get_clean();
		$label = '<div class="tutor-progress-card-category mp-card-kind">' . esc_html( MAYPIANO_SONG_LABEL ) . '</div>';
		echo preg_replace( '/<div class="tutor-progress-card-header">/', '$0' . $label, $html, 1 ); // phpcs:ignore WordPress.Security.EscapeOutput -- Tutor LMS's own card, already escaped there.
		return;
	}
	if ( ! in_array( $template, MAYPIANO_LEARNER_COURSE_PAGES, true ) ) {
		return;
	}
	$html  = (string) ob_get_clean();
	$songs = maypiano_learner_wants_songs();
	maypiano_learner_kind( '' );

	// No tests in these courses, so no "Lượt làm bài kiểm tra" tab.
	$html = (string) preg_replace( '#<a\s[^>]*href="[^"]*my-quiz-attempts[^"]*"[^>]*>.*?</a>#s', '', $html );

	$n_songs = maypiano_learner_count( 'bai-hat' );
	if ( $n_songs && 'dashboard.courses/wishlist' !== $template ) {
		if ( $songs ) {
			// The page's own links (Đang học, Hoàn thành) stay on the songs.
			$html = (string) preg_replace_callback( '#href="([^"]*/courses(?:/active-courses|/completed-courses)?/?)"#', function ( $m ) {
				return 'href="' . esc_url( add_query_arg( 'loai', 'bai-hat', html_entity_decode( $m[1] ) ) ) . '"';
			}, $html );
			$html = str_replace( esc_html__( 'No Courses Found', 'tutor' ), 'Chưa có bài hát nào ở mục này.', $html );
		}
		$n_courses = maypiano_learner_count( 'khoa' );
		$bar       = '<div class="mp-qna-scope mp-kind-scope"><div class="mp-qna-tabs" role="group" aria-label="Chọn khóa học hay bài hát mua riêng">'
			. '<a class="mp-qna-tab' . ( $songs ? '' : ' is-on' ) . '" href="' . esc_url( maypiano_learner_courses_url() ) . '"' . ( $songs ? '' : ' aria-current="true"' ) . '>Khóa học (' . (int) $n_courses . ')</a>'
			. '<a class="mp-qna-tab' . ( $songs ? ' is-on' : '' ) . '" href="' . esc_url( maypiano_learner_courses_url( true ) ) . '"' . ( $songs ? ' aria-current="true"' : '' ) . '>' . esc_html( MAYPIANO_SONG_LABEL ) . ' (' . (int) $n_songs . ')</a>'
			. '</div></div>';
		$spot      = strpos( $html, '<div class="tutor-dashboard-courses-card"' );
		$html      = false === $spot ? $bar . $html : substr_replace( $html, $bar, $spot, 0 );
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- Tutor LMS's own page, already escaped there.
}, 20, 1 );

/** First page: the songs bought one by one, in a list of their own under "Học tiếp". */
add_action( 'tutor_after_continue_learning_section', function () {
	$shown = 3;
	$list  = maypiano_learner_list( 'bai-hat', $shown );
	if ( ! $list || ! function_exists( 'tutor_get_template' ) ) {
		return;
	}
	?>
<div class="tutor-student-dashboard-courses mp-my-songs">
	<div class="tutor-flex tutor-items-center tutor-justify-between tutor-mb-4">
		<div class="tutor-small tutor-font-medium"><?php echo esc_html( MAYPIANO_SONG_LABEL ); ?></div>
		<?php if ( $list->found_posts > $shown ) : ?>
		<a href="<?php echo esc_url( maypiano_learner_courses_url( true ) ); ?>" class="tutor-btn tutor-btn-link tutor-btn-x-small tutor-text-brand tutor-p-none tutor-min-h-0">Xem cả <?php echo (int) $list->found_posts; ?> bài</a>
		<?php endif; ?>
	</div>
	<div class="tutor-flex tutor-flex-column tutor-gap-4">
		<?php
		while ( $list->have_posts() ) {
			$list->the_post();
			$course_id = get_the_ID();
			$card      = apply_filters( 'tutor_dashboard_course_card_template', tutor_get_template( 'dashboard.courses.course-card' ), $course_id );
			if ( file_exists( $card ) ) {
				require $card;
			}
		}
		wp_reset_postdata();
		?>
	</div>
</div>
	<?php
} );

/** The old address of the tests tab leads to the learner's courses. */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_user_logged_in() ) {
		return;
	}
	$page = (string) get_query_var( 'tutor_dashboard_page' );
	$sub  = (string) get_query_var( 'tutor_dashboard_sub_page' );
	if ( 'my-quiz-attempts' === $page || 'my-quiz-attempts' === $sub ) {
		wp_safe_redirect( maypiano_learner_courses_url(), 302 );
		exit;
	}
}, 3 );
