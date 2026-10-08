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
	if ( '' === $guid && function_exists( 'maypiano_song_video_of' ) ) {
		// A lesson of a song sold on its own shows the video of the full-course lesson it comes from.
		$guid = maypiano_song_video_of( $lesson_id );
	}
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
 * An empty text panel under a lesson's video is put away, and a finished lesson gets a link to unmark it. (Back and forward 10 seconds are buttons of the Bunny player itself.)
 */
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'lesson' ) ) {
		return;
	}
	?>
<script>
/* Tutor LMS swaps the lesson in place when the learner moves to another one, so this runs again after every change to the page. */
(function () {
	var UNDO = <?php echo wp_json_encode( wp_nonce_url( admin_url( 'admin-post.php?action=maypiano_lesson_undo' ), 'maypiano_lesson_undo' ) ); ?>.replace(/&amp;/g, '&');
	var sync = function () {
		document.querySelectorAll('.tutor-lesson-wrapper').forEach(function (w) {
			var empty = !w.textContent.trim() && !w.querySelector('img,iframe,video,audio,a');
			var box = w.closest('.tutor-tabs-content') || w;
			if (empty !== box.classList.contains('mp-empty')) { box.classList.toggle('mp-empty', empty); }
		});
		/* A lesson marked as finished by mistake can be unmarked. */
		document.querySelectorAll('.tutor-mark-as-complete-button.completed').forEach(function (b) {
			var form = b.closest('form'), id = form && form.querySelector('[name="lesson_id"]');
			if (!form || !id || form.querySelector('.mp-undo')) { return; }
			var a = document.createElement('a');
			a.className = 'mp-undo';
			a.href = UNDO + '&lesson=' + encodeURIComponent(id.value);
			a.textContent = 'Bỏ đánh dấu';
			form.appendChild(a);
		});
	};
	var due = false;
	var soon = function () { if (due) { return; } due = true; setTimeout(function () { due = false; sync(); }, 60); };
	sync();
	if (window.MutationObserver) { new MutationObserver(soon).observe(document.documentElement, { childList: true, subtree: true }); }
	window.addEventListener('load', sync);
})();
</script>
	<?php
}, 99 );

/**
 * A learner unmarks a lesson they marked as finished by mistake. Tutor LMS keeps "finished" as one note per lesson on the learner's account; the note is removed.
 */
add_action( 'admin_post_maypiano_lesson_undo', function () {
	check_admin_referer( 'maypiano_lesson_undo' );
	$lesson = isset( $_GET['lesson'] ) ? absint( $_GET['lesson'] ) : 0;
	if ( ! $lesson || 'lesson' !== get_post_type( $lesson ) ) {
		wp_die( 'Không tìm thấy bài học.' );
	}
	delete_user_meta( get_current_user_id(), '_tutor_completed_lesson_id_' . $lesson );
	wp_safe_redirect( get_permalink( $lesson ) );
	exit;
} );
