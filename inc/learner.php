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

/**
 * The teacher's menu: no "Thông báo" and no "Lượt làm bài kiểm tra". The site sends no announcements from there
 * and the courses have no tests.
 */
const MAYPIANO_TEACHER_MENU_OFF = array( 'announcements', 'quiz-attempts' );

function maypiano_teacher_menu_trim( $items ) {
	if ( ! is_array( $items ) ) {
		return $items;
	}
	foreach ( MAYPIANO_TEACHER_MENU_OFF as $key ) {
		unset( $items[ $key ] );
	}
	return $items;
}
add_filter( 'tutor_dashboard/nav_items', 'maypiano_teacher_menu_trim', 99 );
add_filter( 'tutor_dashboard/instructor_nav_items', 'maypiano_teacher_menu_trim', 99 );
add_filter( 'tutor_dashboard/nav_items_all', 'maypiano_teacher_menu_trim', 99 );

/** The same two links, hidden wherever the menu is drawn without passing through the lists above. */
add_action( 'wp_head', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'tutor_utils' ) || ! is_page( (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) ) ) {
		return;
	}
	$rules = array();
	foreach ( MAYPIANO_TEACHER_MENU_OFF as $key ) {
		$path = (string) wp_parse_url( tutor_utils()->tutor_dashboard_url( $key ), PHP_URL_PATH );
		if ( '' !== $path && false === strpbrk( $path, '"\\<>' ) ) {
			$rules[] = 'a[href$="' . untrailingslashit( $path ) . '"],a[href$="' . trailingslashit( $path ) . '"]';
		}
	}
	if ( $rules ) {
		echo '<style id="mp-teacher-menu">' . implode( ',', $rules ) . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- paths of the site's own pages, checked above.
	}
}, 30 );

/** Whole courses and songs bought one by one that are open for sale, counted apart (for the teacher's first page). */
function maypiano_teacher_counts() {
	$counts = array( 'khoa' => 0, 'bai-hat' => 0 );
	foreach ( get_posts( array( 'post_type' => 'courses', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $course ) {
		$counts[ '' === (string) get_post_meta( $course, '_maypiano_song', true ) ? 'khoa' : 'bai-hat' ]++;
	}
	return $counts;
}

/**
 * The teacher's first page: no "Tổng thu", no "Số học viên" and no "Học viên học tới đâu" bar (nor the number of
 * learners in the greeting); "Số khóa học" counts whole courses only, with the songs sold one by one in a box of their
 * own next to it. Tutor LMS draws these boxes, so they are found by their (Vietnamese) titles once the page is shown.
 */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || ! function_exists( 'tutor_utils' ) || ! is_page( (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' ) ) ) {
		return;
	}
	$page = (string) get_query_var( 'tutor_dashboard_page' );
	if ( '' !== $page && 'index' !== $page && 'dashboard' !== $page ) {
		return;
	}
	$counts = maypiano_teacher_counts();
	?>
<script id="mp-teacher-home">
(function () {
	var COURSES = <?php echo (int) $counts['khoa']; ?>, SONGS = <?php echo (int) $counts['bai-hat']; ?>;
	var OFF = ['Tổng thu', 'Số học viên', 'Học viên học tới đâu'];
	var TITLES = OFF.concat(['Số khóa học', 'Điểm đánh giá', 'Đánh giá mới của học viên', 'Khóa học nổi bật', '<?php echo esc_js( MAYPIANO_SONG_LABEL ); ?>']);
	var STOP = /^(BODY|MAIN|HTML)$/;
	function own(el) { return (el.textContent || '').replace(/\s+/g, ' ').trim(); }
	function titled(text) {
		var all = document.querySelectorAll('h1,h2,h3,h4,h5,h6,div,span,p,strong');
		for (var i = 0; i < all.length; i++) {
			if (!all[i].children.length && own(all[i]) === text) { return all[i]; }
		}
		return null;
	}
	// The box around a title: the largest block holding that title and no other.
	function box(el) {
		while (el.parentElement && !STOP.test(el.parentElement.tagName) && !el.parentElement.classList.contains('tutor-dashboard-content')) {
			var p = el.parentElement, t = own(p), n = 0;
			for (var i = 0; i < TITLES.length; i++) { if (t.indexOf(TITLES[i]) !== -1) { n++; } }
			if (n > 1) { break; }
			el = p;
		}
		return el;
	}
	function setNumber(card, n) {
		var all = card.querySelectorAll('*');
		for (var i = 0; i < all.length; i++) {
			if (!all[i].children.length && /^\d[\d.,]*$/.test(own(all[i]))) { all[i].textContent = String(n); return; }
		}
	}
	function run() {
		var done = false;
		OFF.forEach(function (text) {
			var el = titled(text);
			if (el) { box(el).style.display = 'none'; done = true; }
		});
		var label = titled('Số khóa học');
		if (label && !document.querySelector('.mp-song-count')) {
			var card = box(label), copy = card.cloneNode(true);
			setNumber(card, COURSES);
			copy.classList.add('mp-song-count');
			var t = copy.querySelectorAll('*');
			for (var i = 0; i < t.length; i++) {
				if (!t[i].children.length && own(t[i]) === 'Số khóa học') { t[i].textContent = '<?php echo esc_js( MAYPIANO_SONG_LABEL ); ?>'; }
			}
			setNumber(copy, SONGS);
			card.parentNode.insertBefore(copy, card.nextSibling);
			var grid = card.parentNode, cols = getComputedStyle(grid).gridTemplateColumns;
			if (getComputedStyle(grid).display.indexOf('grid') !== -1 && cols && cols.split(' ').length === 4) {
				grid.style.gridTemplateColumns = 'repeat(3, minmax(0, 1fr))';
			}
			done = true;
		}
		// Greeting line: "N khóa học đang hoạt động • M học viên đã đăng ký" becomes the number of whole courses only.
		// The innermost block holding both halves is the line itself.
		var lines = document.querySelectorAll('div,p,span'), line = null;
		for (var j = 0; j < lines.length; j++) {
			var s = own(lines[j]);
			if (/khóa học đang hoạt động/.test(s) && /học viên đã đăng ký/.test(s)) { line = lines[j]; }
		}
		if (line && own(line).indexOf('Xin chào') === -1) {
			line.textContent = COURSES + ' khóa học' + (SONGS ? ' • ' + SONGS + ' bài hát mua riêng' : '');
		}
		return done;
	}
	function start() {
		if (run()) { return; }
		var tries = 0, timer = setInterval(function () { if (run() || ++tries > 20) { clearInterval(timer); } }, 250);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
	<?php
}, 30 );
