<?php
/**
 * Lesson videos, kept on Bunny Stream.
 *
 * The keys live in the site's settings, never in this repository. Each lesson remembers only which Bunny video is
 * its own. The player link is made fresh on every view, signed, and good for a few hours, and only for someone who
 * may watch that lesson: an enrolled learner, the teacher, or anyone when the lesson is a free preview.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_BUNNY_LINK_HOURS = 6;

function maypiano_bunny_keys() {
	return array(
		'maypiano_bunny_library' => array( 'Số thư viện (Library ID)', 'Bunny > Stream > May Piano > API.' ),
		'maypiano_bunny_api'     => array( 'Khóa API của thư viện', 'Cùng trang đó, dòng API Key (không phải Read-Only). Dùng để tìm video của từng bài.' ),
		'maypiano_bunny_token'   => array( 'Khóa ký link xem', 'Bunny > Stream > May Piano > Security > Token authentication key. Dùng để mở video cho đúng học viên.' ),
	);
}

/** An empty box on the settings page means "keep what is saved", so the keys never have to be shown again. */
add_action( 'admin_init', function () {
	foreach ( array_keys( maypiano_bunny_keys() ) as $key ) {
		register_setting( 'maypiano', $key, array(
			'sanitize_callback' => function ( $value ) use ( $key ) {
				$value = trim( sanitize_text_field( (string) $value ) );
				return '' === $value ? (string) get_option( $key, '' ) : $value;
			},
		) );
	}
} );

function maypiano_bunny_ready() {
	foreach ( array_keys( maypiano_bunny_keys() ) as $key ) {
		if ( '' === trim( (string) get_option( $key, '' ) ) ) {
			return false;
		}
	}
	return true;
}

/** Every video in the library, as title => id. Null when Bunny cannot be read. */
function maypiano_bunny_videos() {
	$library = rawurlencode( trim( (string) get_option( 'maypiano_bunny_library' ) ) );
	$found   = array();
	for ( $page = 1; $page <= 20; $page++ ) {
		$response = wp_remote_get( 'https://video.bunnycdn.com/library/' . $library . '/videos?itemsPerPage=100&page=' . $page, array(
			'timeout' => 20,
			'headers' => array(
				'AccessKey' => trim( (string) get_option( 'maypiano_bunny_api' ) ),
				'accept'    => 'application/json',
			),
		) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['items'] ) ) {
			break;
		}
		foreach ( $body['items'] as $item ) {
			$found[ (string) $item['title'] ] = (string) $item['guid'];
		}
		if ( count( $found ) >= (int) $body['totalItems'] ) {
			break;
		}
	}
	return $found;
}

/**
 * Gives each lesson its video. A lesson's video on Bunny is titled "<video_prefix> <two-digit lesson number> · ...",
 * with the prefix taken from the course file in data/. Returns one line per course for the settings page.
 */
function maypiano_bunny_match() {
	if ( ! maypiano_bunny_ready() ) {
		return array( 'Chưa đủ ba khóa Bunny, nên các bài học chưa có video.' );
	}
	$videos = maypiano_bunny_videos();
	if ( null === $videos ) {
		return array( 'Không đọc được thư viện Bunny. Kiểm tra lại số thư viện và khóa API.' );
	}
	$lines = array();
	foreach ( (array) glob( get_theme_file_path( 'data/*.json' ) ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( empty( $data['course'] ) || empty( $data['video_prefix'] ) || empty( $data['topics'] ) ) {
			continue;
		}
		$slug  = sanitize_title( $data['course'] );
		$total = 0;
		$with  = 0;
		foreach ( $data['topics'] as $topic ) {
			foreach ( $topic['lessons'] as $lesson ) {
				$total++;
				$lesson_id = maypiano_curriculum_find( 'lesson', $slug . '/bai-' . (int) $lesson['no'] );
				if ( ! $lesson_id ) {
					continue;
				}
				$start = $data['video_prefix'] . ' ' . sprintf( '%02d', (int) $lesson['no'] ) . ' ';
				foreach ( $videos as $title => $guid ) {
					if ( 0 === strpos( $title, $start ) ) {
						update_post_meta( $lesson_id, '_maypiano_bunny', $guid );
						$with++;
						break;
					}
				}
			}
		}
		$lines[] = $slug . ': ' . $with . ' trên ' . $total . ' bài đã có video.';
	}
	return $lines ? $lines : array( 'Chưa có khóa nào khai báo video.' );
}

/** May the person looking at this lesson watch its video? */
function maypiano_bunny_may_watch( $lesson_id ) {
	if ( get_post_meta( $lesson_id, '_is_preview', true ) ) {
		return true;
	}
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'edit_post', $lesson_id ) ) {
		return true;
	}
	$course_id = (int) get_post_meta( $lesson_id, '_tutor_course_id_for_lesson', true );
	return $course_id && function_exists( 'tutor_utils' ) && (bool) tutor_utils()->is_enrolled( $course_id, get_current_user_id() );
}

/** The player for one video, with a link that stops working after a few hours. */
function maypiano_bunny_player( $guid ) {
	$library = trim( (string) get_option( 'maypiano_bunny_library' ) );
	$expires = time() + MAYPIANO_BUNNY_LINK_HOURS * HOUR_IN_SECONDS;
	$token   = hash( 'sha256', trim( (string) get_option( 'maypiano_bunny_token' ) ) . $guid . $expires );
	$src     = 'https://iframe.mediadelivery.net/embed/' . rawurlencode( $library ) . '/' . rawurlencode( $guid ) . '?token=' . $token . '&expires=' . $expires . '&autoplay=false&preload=true';
	return '<iframe src="' . esc_url( $src ) . '" loading="lazy" class="mp-bunny" style="border:0;position:absolute;top:0;left:0;width:100%;height:100%" allow="accelerometer; gyroscope; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>';
}

/**
 * Tutor LMS reads a lesson's video from its "_video" record. For a lesson with a Bunny video, that record is
 * answered here with the signed player, so the link is never stored and never goes stale.
 */
function maypiano_bunny_video_record( $value, $lesson_id, $key, $single ) {
	if ( '_video' !== $key || is_admin() || ! maypiano_bunny_ready() ) {
		return $value;
	}
	$guid = (string) get_post_meta( $lesson_id, '_maypiano_bunny', true );
	if ( '' === $guid || ! maypiano_bunny_may_watch( $lesson_id ) ) {
		return $value;
	}
	remove_filter( 'get_post_metadata', 'maypiano_bunny_video_record', 10 );
	$saved = get_post_meta( $lesson_id, '_video', true );
	add_filter( 'get_post_metadata', 'maypiano_bunny_video_record', 10, 4 );
	$video                    = is_array( $saved ) ? $saved : array();
	$video['source']          = 'embedded';
	$video['source_embedded'] = maypiano_bunny_player( $guid );
	return $single ? array( $video ) : array( $video );
}
add_filter( 'get_post_metadata', 'maypiano_bunny_video_record', 10, 4 );

/** The player fills the 16:9 frame Tutor LMS draws for it, on any screen. */
add_action( 'wp_head', function () {
	if ( is_singular( 'lesson' ) ) {
		echo '<style>.tutor-video-player .tutor-ratio{position:relative;display:block;width:100%;aspect-ratio:16/9;padding:0}.tutor-video-player .tutor-ratio::before{content:none}</style>';
	}
} );

/** Jetpack's "related posts" box has no place under a lesson or on a course page. */
add_filter( 'jetpack_relatedposts_filter_enabled_for_request', function ( $on ) {
	return is_singular( array( 'lesson', 'courses' ) ) ? false : $on;
} );

/** The Bunny part of Settings > May Piano. Opening the page also looks for new videos. */
function maypiano_bunny_settings_section() {
	echo '<h2>Video bài học (Bunny)</h2>';
	foreach ( maypiano_bunny_match() as $line ) {
		echo '<p><strong>' . esc_html( $line ) . '</strong></p>';
	}
	echo '<p class="description">Ô nào đã lưu thì để trống là giữ nguyên. Site không hiện lại khóa đã lưu.</p>';
	echo '<table class="form-table" role="presentation">';
	foreach ( maypiano_bunny_keys() as $key => $field ) {
		$saved = '' !== trim( (string) get_option( $key, '' ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
		echo '<input type="password" autocomplete="off" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="" placeholder="' . esc_attr( $saved ? 'Đã lưu' : 'Chưa có' ) . '">';
		echo '<p class="description">' . esc_html( $field[1] ) . '</p></td></tr>';
	}
	echo '</table>';
}

/**
 * Under a lesson's video: back 10 seconds and forward 10 seconds. The two talk to the Bunny player the standard way (player.js messages).
 * Also here: the Previous and Next buttons follow the order of the lesson list, and an empty text panel under the video is put away.
 */
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'lesson' ) ) {
		return;
	}
	?>
<script>
(function () {
	var frame = document.querySelector('iframe.mp-bunny');
	if (frame) {
		var say = function (m) { m.context = 'player.js'; m.version = '0.0.11'; frame.contentWindow.postMessage(JSON.stringify(m), 'https://iframe.mediadelivery.net'); };
		var want = 0;
		window.addEventListener('message', function (e) {
			if (e.origin !== 'https://iframe.mediadelivery.net') { return; }
			var d; try { d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (x) { return; }
			if (!d || d.context !== 'player.js' || d.listener !== 'mp-skip' || d.event !== 'getCurrentTime') { return; }
			say({ method: 'setCurrentTime', value: Math.max(0, Number(d.value) + want) });
		});
		var bar = document.createElement('div');
		bar.className = 'mp-skip';
		[[-10, 'Lùi 10 giây', 'M11 5 4 12l7 7M4 12h16'], [10, 'Tới 10 giây', 'm13 5 7 7-7 7M20 12H4']].forEach(function (b) {
			var el = document.createElement('button');
			el.type = 'button';
			el.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + b[2] + '"></path></svg><span>' + b[1] + '</span>';
			if (b[0] > 0) { el.appendChild(el.firstChild); }
			el.addEventListener('click', function () { want = b[0]; say({ method: 'getCurrentTime', listener: 'mp-skip' }); });
			bar.appendChild(el);
		});
		// The row of Previous and Next is the one place on this page Tutor LMS does not redraw, so the two buttons live there, between them.
		var row = document.querySelector('.tutor-learning-area-footer');
		if (row && row.children.length) { row.insertBefore(bar, row.lastElementChild); row.classList.add('mp-has-skip'); }
		else { var player = frame.closest('.tutor-video-player') || frame.parentNode; player.parentNode.insertBefore(bar, player.nextSibling); }
	}
	document.querySelectorAll('.tutor-lesson-wrapper').forEach(function (w) {
		if (!w.textContent.trim() && !w.querySelector('img,iframe,video,audio,a')) { (w.closest('.tutor-tabs-content') || w).classList.add('mp-empty'); }
	});
	var items = Array.prototype.slice.call(document.querySelectorAll('a.tutor-learning-nav-item[href]'));
	var at = items.findIndex(function (a) { return a.classList.contains('active'); });
	var foot = document.querySelectorAll('.tutor-learning-area-footer a.tutor-btn');
	if (at >= 0 && foot.length === 2) {
		[[foot[0], items[at - 1]], [foot[1], items[at + 1]]].forEach(function (pair) {
			var btn = pair[0], to = pair[1];
			if (!to) { btn.style.visibility = 'hidden'; return; }
			btn.href = to.href;
			btn.removeAttribute('disabled');
			btn.classList.remove('disabled');
			btn.style.visibility = 'visible';
			btn.addEventListener('click', function (e) { e.stopPropagation(); window.location.href = to.href; e.preventDefault(); }, true);
		});
	}
})();
</script>
	<?php
}, 99 );
