<?php
/**
 * Tài liệu khóa học: the PDFs (sheet music, lesson notes) that go with a course.
 *
 * The files are NOT in the theme (the repo is public). The owner uploads them at Cài đặt > May Piano > Tài liệu khóa học,
 * several at once, and the file's own name says where it belongs:
 *
 *   MayPiano-<mã khóa>-Bai-<số bài>-<tên>.pdf   goes with that one lesson
 *   MayPiano-<mã khóa>-Phan-<số phần>-<tên>.pdf goes with every lesson of that part
 *   MayPiano-<mã khóa>-<tên>.pdf                belongs to the whole course
 *
 * <mã khóa> is "doc_code" in the course's file in data/ (DemHat, ThanhCa, SoloCanBan, SoloNangCao).
 * A learner sees a lesson's files right under the video, and every file of the course in a last lesson named
 * "Tài liệu khóa học", which the site makes once the course has a file. Only a learner of the course (or the buyer of
 * a song, for that song's lessons) can download: the link checks who is asking every time.
 * The name a learner sees comes from the last part of the file name, through data/tai-lieu.json (see maypiano_docs_title).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_DOCS_LESSON_KEY = 'tai-lieu-chung';

/** Where the uploaded PDFs live. */
function maypiano_docs_dir() {
	$up = wp_upload_dir();
	return trailingslashit( $up['basedir'] ) . 'mp-docs';
}

/** Every course that takes materials: course address => array( code, name ). */
function maypiano_docs_courses() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	foreach ( (array) glob( get_theme_file_path( 'data/*.json' ) ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( empty( $data['course'] ) || empty( $data['doc_code'] ) ) {
			continue;
		}
		$out[ sanitize_title( $data['course'] ) ] = preg_replace( '/[^A-Za-z0-9]/', '', (string) $data['doc_code'] );
	}
	return $out;
}

/**
 * The name a learner sees for a file, from the last part of its file name ("ba-ke-con-nghe", "tinh-thoi-xot-xa-tone-g").
 * data/tai-lieu.json holds the names with their Vietnamese accents; a "-tone-x" ending becomes "(tone X)".
 */
function maypiano_docs_title( $rest ) {
	static $names = null;
	if ( null === $names ) {
		$file  = get_theme_file_path( 'data/tai-lieu.json' );
		$data  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$names = array_change_key_case( (array) ( $data['names'] ?? array() ), CASE_LOWER );
	}
	$slug = strtolower( str_replace( '_', '-', (string) $rest ) );
	$tone = '';
	if ( preg_match( '/^(.+)-tone-([a-g](?:b|#|m|bm|#m)?)$/', $slug, $m ) ) {
		$slug = $m[1];
		$tone = ' (tone ' . strtoupper( $m[2][0] ) . substr( $m[2], 1 ) . ')';
	}
	if ( '' === $slug ) {
		return '';
	}
	return ( isset( $names[ $slug ] ) ? (string) $names[ $slug ] : ucfirst( str_replace( '-', ' ', $slug ) ) ) . $tone;
}

/**
 * Reads a file name. Returns array( key, course, lesson, part, rest ) or null when the name does not name a course.
 * key is the name in lower case without ".pdf": it is what the download link carries.
 */
function maypiano_docs_parse( $name ) {
	$base = preg_replace( '/\.pdf$/i', '', sanitize_file_name( (string) $name ) );
	if ( ! preg_match( '/^MayPiano[-_]([A-Za-z0-9]+)(?:[-_](.*))?$/i', $base, $m ) ) {
		return null;
	}
	$course = '';
	foreach ( maypiano_docs_courses() as $slug => $code ) {
		if ( 0 === strcasecmp( $code, $m[1] ) ) {
			$course = $slug;
		}
	}
	if ( '' === $course ) {
		return null;
	}
	$rest   = isset( $m[2] ) ? $m[2] : '';
	$lesson = 0;
	$part   = 0;
	if ( preg_match( '/^Bai[-_]?(\d+)(?:[-_](.*))?$/i', $rest, $b ) ) {
		$lesson = (int) $b[1];
		$rest   = isset( $b[2] ) ? $b[2] : '';
	} elseif ( preg_match( '/^Phan[-_]?(\d+)(?:[-_](.*))?$/i', $rest, $p ) ) {
		$part = (int) $p[1];
		$rest = isset( $p[2] ) ? $p[2] : '';
	}
	return array(
		'key'    => strtolower( $base ),
		'course' => $course,
		'lesson' => $lesson,
		'part'   => $part,
		'rest'   => $rest,
	);
}

/** Every uploaded file that is really there, by key: key, course, lesson, part, title, size, path, name. */
function maypiano_docs_all() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out    = array();
	$dir    = trailingslashit( maypiano_docs_dir() );
	foreach ( (array) get_option( 'maypiano_doc_files', array() ) as $key => $row ) {
		$doc  = maypiano_docs_parse( (string) ( $row['name'] ?? '' ) );
		$path = $dir . basename( (string) ( $row['file'] ?? '' ) );
		if ( ! $doc || $doc['key'] !== $key || ! is_file( $path ) ) {
			continue;
		}
		$title = maypiano_docs_title( $doc['rest'] );
		if ( '' === $title ) {
			$title = $doc['lesson'] ? 'Tài liệu bài học' : ( $doc['part'] ? 'Tài liệu Phần ' . $doc['part'] : 'Tài liệu khóa học' );
		}
		$out[ $key ] = $doc + array(
			'title' => $title,
			'size'  => (int) filesize( $path ),
			'path'  => $path,
			'name'  => (string) $row['name'],
		);
	}
	uasort( $out, function ( $a, $b ) {
		return array( $a['course'], $a['part'] ? 1 : ( $a['lesson'] ? 2 : 0 ), $a['part'], $a['lesson'], $a['title'] ) <=> array( $b['course'], $b['part'] ? 1 : ( $b['lesson'] ? 2 : 0 ), $b['part'], $b['lesson'], $b['title'] );
	} );
	return $out;
}

/** The files of one course. */
function maypiano_docs_of_course( $slug ) {
	return array_filter( maypiano_docs_all(), function ( $doc ) use ( $slug ) {
		return $doc['course'] === $slug;
	} );
}

/**
 * Which course lesson a lesson page stands for: array( course address, lesson number, part number ), or null.
 * A lesson of a song sold on its own stands for the full-course lesson it shows.
 */
function maypiano_docs_place( $lesson_id ) {
	$key = (string) get_post_meta( $lesson_id, '_maypiano_song_src', true );
	$own = (string) get_post_meta( $lesson_id, '_maypiano_key', true );
	$key = '' !== $key ? $key : $own;
	if ( ! preg_match( '#^([a-z0-9-]+)/bai-(\d+)$#', $key, $m ) ) {
		return null;
	}
	$source = '' !== (string) get_post_meta( $lesson_id, '_maypiano_song_src', true ) ? maypiano_curriculum_find( 'lesson', $key ) : $lesson_id;
	$part   = 0;
	if ( $source && preg_match( '#/phan-(\d+)$#', (string) get_post_meta( (int) wp_get_post_parent_id( $source ), '_maypiano_key', true ), $p ) ) {
		$part = (int) $p[1];
	}
	return array( $m[1], (int) $m[2], $part );
}

/** The course at an address, or 0. */
function maypiano_docs_course_id( $slug ) {
	static $known = array();
	if ( ! isset( $known[ $slug ] ) ) {
		$found          = get_posts( array( 'post_type' => 'courses', 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$known[ $slug ] = $found ? (int) $found[0] : 0;
	}
	return $known[ $slug ];
}

/** May the signed-in person download this file? */
function maypiano_docs_may_get( $doc ) {
	if ( ! is_user_logged_in() || ! function_exists( 'tutor_utils' ) ) {
		return false;
	}
	$user   = get_current_user_id();
	$course = maypiano_docs_course_id( $doc['course'] );
	if ( $course && ( current_user_can( 'edit_post', $course ) || tutor_utils()->is_enrolled( $course, $user ) ) ) {
		return true;
	}
	// The buyer of one song gets the files of that song's lessons, nothing else of the course.
	if ( $doc['lesson'] && function_exists( 'maypiano_songs' ) ) {
		foreach ( maypiano_songs() as $song ) {
			if ( $song['from'] !== $doc['course'] || ! in_array( $doc['lesson'], $song['lessons'], true ) ) {
				continue;
			}
			$small = maypiano_song_course( $song['slug'] );
			if ( $small && tutor_utils()->is_enrolled( $small, $user ) ) {
				return true;
			}
		}
	}
	return false;
}

function maypiano_docs_url( $doc ) {
	return add_query_arg( 'mp_tl', rawurlencode( $doc['key'] ), home_url( '/' ) );
}

/** "PDF, 1,2 MB". */
function maypiano_docs_meta( $doc ) {
	$mb = $doc['size'] / 1048576;
	return 'PDF, ' . ( $mb >= 1 ? str_replace( '.', ',', (string) round( $mb, 1 ) ) . ' MB' : max( 1, (int) round( $doc['size'] / 1024 ) ) . ' KB' );
}

/** One list of files with their download buttons. */
function maypiano_docs_list_html( $docs ) {
	$html = '<ul class="mp-docs-list">';
	foreach ( $docs as $doc ) {
		$html .= '<li><span class="mp-docs-name">' . esc_html( $doc['title'] ) . '</span><span class="mp-docs-meta">' . esc_html( maypiano_docs_meta( $doc ) ) . '</span>'
			. '<a class="mp-docs-get" href="' . esc_url( maypiano_docs_url( $doc ) ) . '" download>Tải về<span class="screen-reader-text">: ' . esc_html( $doc['title'] ) . '</span></a></li>';
	}
	return $html . '</ul>';
}

/** Hands the PDF to a learner who may have it. */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['mp_tl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( maypiano_login_url(), 302 );
		exit;
	}
	$key  = is_string( $_GET['mp_tl'] ) ? strtolower( sanitize_file_name( wp_unslash( $_GET['mp_tl'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$all  = maypiano_docs_all();
	$doc  = isset( $all[ $key ] ) ? $all[ $key ] : null;
	$busy = maypiano_throttled( 'tl', 200 );
	if ( ! $doc || $busy || ! maypiano_docs_may_get( $doc ) ) {
		wp_die( 'Tài liệu này dành cho học viên của khóa học. Bạn đăng nhập đúng tài khoản học rồi mở lại từ trang bài học nhé.', 'Không tải được tài liệu', array( 'response' => 404 ) );
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9._-]/', '', $doc['name'] ) . '"' );
	header( 'Content-Length: ' . $doc['size'] );
	readfile( $doc['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}, 2 );

/** True on the last lesson of a course, the one that holds every file. */
function maypiano_docs_is_all_lesson( $lesson_id ) {
	return (bool) preg_match( '#/' . MAYPIANO_DOCS_LESSON_KEY . '$#', (string) get_post_meta( $lesson_id, '_maypiano_key', true ) );
}

/**
 * The lesson page: this lesson's files right under the video, above Hỏi đáp.
 * On the "Tài liệu khóa học" lesson: every file of the course, part by part.
 */
add_action( 'tutor_load_template_after', function ( $template ) {
	if ( 'learning-area.lesson.footer' !== $template || ! is_singular( 'lesson' ) ) {
		return;
	}
	$lesson = (int) get_queried_object_id();
	if ( maypiano_docs_is_all_lesson( $lesson ) ) {
		$slug  = (string) strtok( (string) get_post_meta( $lesson, '_maypiano_key', true ), '/' );
		$docs  = array_filter( maypiano_docs_of_course( $slug ), 'maypiano_docs_may_get' );
		$whole = array();
		$parts = array();
		$each  = array();
		foreach ( $docs as $doc ) {
			if ( $doc['lesson'] ) {
				$each[ $doc['lesson'] ][] = $doc;
			} elseif ( $doc['part'] ) {
				$parts[ $doc['part'] ][] = $doc;
			} else {
				$whole[] = $doc;
			}
		}
		echo '<section class="mp-docs mp-docs-all" id="mp-tai-lieu">';
		if ( ! $docs ) {
			echo '<p class="mp-docs-lead">Mây đang chuẩn bị tài liệu cho khóa này. Bạn quay lại sau nhé.</p></section>';
			return;
		}
		echo '<p class="mp-docs-lead">Đây là toàn bộ tài liệu của khóa. Bạn tải về máy để in ra hoặc xem lúc tập đàn nhé.</p>';
		if ( $whole ) {
			echo '<h2 class="mp-docs-title">Tài liệu chung của khóa</h2>' . maypiano_docs_list_html( $whole ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		foreach ( $parts as $no => $list ) {
			$topic = maypiano_curriculum_find( 'topics', $slug . '/phan-' . (int) $no );
			echo '<h2 class="mp-docs-title">' . esc_html( $topic ? get_the_title( $topic ) : 'Phần ' . (int) $no ) . '</h2>' . maypiano_docs_list_html( $list ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $each ) {
			echo '<h2 class="mp-docs-title">Tài liệu theo từng bài</h2>';
			foreach ( $each as $no => $list ) {
				$of = maypiano_curriculum_find( 'lesson', $slug . '/bai-' . (int) $no );
				echo '<h3 class="mp-docs-sub">' . ( $of ? '<a href="' . esc_url( get_permalink( $of ) ) . '">' . esc_html( get_the_title( $of ) ) . '</a>' : 'Bài ' . (int) $no ) . '</h3>' . maypiano_docs_list_html( $list ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '</section>';
		return;
	}
	$place = maypiano_docs_place( $lesson );
	if ( ! $place ) {
		return;
	}
	$mine = array();
	$part = array();
	foreach ( maypiano_docs_of_course( $place[0] ) as $doc ) {
		if ( ! maypiano_docs_may_get( $doc ) ) {
			continue;
		}
		if ( $doc['lesson'] && $doc['lesson'] === $place[1] ) {
			$mine[] = $doc;
		} elseif ( $doc['part'] && $doc['part'] === $place[2] ) {
			$part[] = $doc;
		}
	}
	if ( ! $mine && ! $part ) {
		return;
	}
	echo '<section class="mp-docs" id="mp-tai-lieu">';
	if ( $mine ) {
		echo '<h2 class="mp-docs-title">Tài liệu của bài này</h2>' . maypiano_docs_list_html( $mine ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if ( $part ) {
		echo '<h2 class="mp-docs-title">' . ( $mine ? 'Tài liệu chung của phần này' : 'Tài liệu của bài này' ) . '</h2>';
		if ( ! $mine ) {
			echo '<p class="mp-docs-lead">File này dùng chung cho các bài trong cùng một phần.</p>';
		}
		echo maypiano_docs_list_html( $part ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</section>';
}, 15, 1 );

/**
 * Makes the last part and lesson of a course, "Tài liệu khóa học", once the course has a file. Never removed after:
 * a learner who has opened it keeps finding it.
 */
function maypiano_docs_ensure_lesson( $slug ) {
	$course = maypiano_docs_course_id( $slug );
	if ( ! $course || ! maypiano_docs_of_course( $slug ) || ! function_exists( 'maypiano_curriculum_put' ) ) {
		return 0;
	}
	$topic = maypiano_curriculum_put( 'topics', $slug . '/phan-' . MAYPIANO_DOCS_LESSON_KEY, 'Tài liệu khóa học', $course, 900 );
	if ( ! $topic ) {
		return 0;
	}
	$lesson = maypiano_curriculum_put( 'lesson', $slug . '/' . MAYPIANO_DOCS_LESSON_KEY, 'Tải tài liệu của khóa', $topic, 1 );
	if ( $lesson ) {
		update_post_meta( $lesson, '_tutor_course_id_for_lesson', $course );
		if ( ! is_array( get_post_meta( $lesson, '_video', true ) ) ) {
			update_post_meta( $lesson, '_video', array( 'source' => '-1' ) );
		}
	}
	return (int) $lesson;
}

/** Cài đặt > May Piano > Tài liệu khóa học: the owner picks the PDFs, several at once. */
add_action( 'admin_post_maypiano_docs_upload', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'maypiano_docs_upload' );
	$files = (array) get_option( 'maypiano_doc_files', array() );
	$dir   = maypiano_docs_dir();
	wp_mkdir_p( $dir );
	if ( ! file_exists( $dir . '/index.html' ) ) {
		file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$done = array();
	$skip = array();
	$up   = isset( $_FILES['mp_docs'] ) ? $_FILES['mp_docs'] : array( 'name' => array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( (array) $up['name'] as $i => $name ) {
		$name = sanitize_file_name( (string) $name );
		$doc  = maypiano_docs_parse( $name );
		$tmp  = (string) $up['tmp_name'][ $i ];
		$head = is_uploaded_file( $tmp ) ? (string) file_get_contents( $tmp, false, null, 0, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $doc || UPLOAD_ERR_OK !== (int) $up['error'][ $i ] || '%PDF-' !== $head ) {
			$skip[] = $name;
			continue;
		}
		$stored = substr( $doc['key'], 0, 60 ) . '-' . strtolower( wp_generate_password( 28, false ) ) . '.pdf';
		if ( ! move_uploaded_file( $tmp, $dir . '/' . $stored ) ) {
			$skip[] = $name;
			continue;
		}
		if ( ! empty( $files[ $doc['key'] ]['file'] ) && is_file( $dir . '/' . basename( $files[ $doc['key'] ]['file'] ) ) ) {
			unlink( $dir . '/' . basename( $files[ $doc['key'] ]['file'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$files[ $doc['key'] ] = array( 'file' => $stored, 'name' => preg_replace( '/\.pdf$/i', '', $name ) . '.pdf', 'at' => time() );
		$done[]               = $name;
	}
	update_option( 'maypiano_doc_files', $files, false );
	set_transient( 'maypiano_docs_upload_note', array( $done, $skip ), 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano&mp_docs=1#mp-docs' ) );
	exit;
} );

/** Takes one file away. */
add_action( 'admin_post_maypiano_docs_remove', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'maypiano_docs_remove' );
	$key   = isset( $_GET['doc'] ) ? strtolower( sanitize_file_name( wp_unslash( $_GET['doc'] ) ) ) : '';
	$files = (array) get_option( 'maypiano_doc_files', array() );
	if ( isset( $files[ $key ] ) ) {
		$path = trailingslashit( maypiano_docs_dir() ) . basename( (string) $files[ $key ]['file'] );
		if ( is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		unset( $files[ $key ] );
		update_option( 'maypiano_doc_files', $files, false );
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#mp-docs' ) );
	exit;
} );

function maypiano_docs_settings_section() {
	$courses = maypiano_docs_courses();
	// Opening the page after an upload is when the "Tài liệu khóa học" lesson is made for a course that now has files.
	foreach ( array_keys( $courses ) as $slug ) {
		if ( maypiano_docs_of_course( $slug ) && ! maypiano_curriculum_find( 'lesson', $slug . '/' . MAYPIANO_DOCS_LESSON_KEY ) ) {
			maypiano_docs_ensure_lesson( $slug );
		}
	}
	echo '<h2 id="mp-docs">Tài liệu khóa học</h2>';
	$note = get_transient( 'maypiano_docs_upload_note' );
	if ( is_array( $note ) ) {
		delete_transient( 'maypiano_docs_upload_note' );
		if ( $note[0] ) {
			echo '<div class="notice notice-success inline"><p>Đã nhận: ' . esc_html( implode( ', ', $note[0] ) ) . '.</p></div>';
		}
		if ( $note[1] ) {
			echo '<div class="notice notice-warning inline"><p>Bỏ qua (không phải PDF, hoặc tên file không đúng mã khóa nào): ' . esc_html( implode( ', ', $note[1] ) ) . '.</p></div>';
		}
	}
	echo '<p>Tên file nói file thuộc về đâu: <code>MayPiano-&lt;mã khóa&gt;-Bai-&lt;số bài&gt;-&lt;tên&gt;.pdf</code> đi với một bài, <code>MayPiano-&lt;mã khóa&gt;-Phan-&lt;số phần&gt;.pdf</code> đi với mọi bài của phần đó, <code>MayPiano-&lt;mã khóa&gt;-&lt;tên&gt;.pdf</code> là tài liệu chung của khóa. Tải lên file trùng tên là thay file cũ. Học viên của khóa thấy file ngay dưới video của bài, và thấy mọi file ở bài cuối "Tài liệu khóa học".</p>';
	echo '<p>Mã khóa: ';
	$codes = array();
	foreach ( $courses as $slug => $code ) {
		$codes[] = '<code>' . esc_html( $code ) . '</code> (' . esc_html( $slug ) . ')';
	}
	echo implode( ', ', $codes ) . '.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	$all = maypiano_docs_all();
	if ( $all ) {
		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Khóa</th><th>Đi với</th><th>Tên học viên thấy</th><th>File</th><th>Cỡ</th><th></th></tr></thead><tbody>';
		foreach ( $all as $doc ) {
			$where  = $doc['lesson'] ? 'Bài ' . $doc['lesson'] : ( $doc['part'] ? 'Phần ' . $doc['part'] : 'Cả khóa' );
			$remove = wp_nonce_url( admin_url( 'admin-post.php?action=maypiano_docs_remove&doc=' . rawurlencode( $doc['key'] ) ), 'maypiano_docs_remove' );
			if ( $doc['lesson'] && ! maypiano_curriculum_find( 'lesson', $doc['course'] . '/bai-' . $doc['lesson'] ) ) {
				$where .= ' <strong>(khóa chưa có bài số này)</strong>';
			}
			echo '<tr><td>' . esc_html( $doc['course'] ) . '</td><td>' . wp_kses_post( $where ) . '</td><td>' . esc_html( $doc['title'] ) . '</td><td><code>' . esc_html( $doc['name'] ) . '</code></td><td>' . esc_html( maypiano_docs_meta( $doc ) ) . '</td><td><a href="' . esc_url( $remove ) . '" onclick="return confirm(\'Gỡ file này khỏi site?\')">Gỡ</a></td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p><strong>Chưa có file nào.</strong></p>';
	}
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
	wp_nonce_field( 'maypiano_docs_upload' );
	echo '<input type="hidden" name="action" value="maypiano_docs_upload"><input type="file" name="mp_docs[]" accept="application/pdf" multiple required> ';
	submit_button( 'Tải tài liệu lên', 'secondary', 'submit', false );
	echo '</form>';
}
