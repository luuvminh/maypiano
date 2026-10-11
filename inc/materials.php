<?php
/**
 * Lesson handouts (tài liệu): PDFs a learner downloads from the lesson page.
 *
 * Which PDF belongs to which lesson is listed in data/tai-lieu-<course>.json. The files are not in the repo.
 * They reach the site one of two ways: the owner picks them at Cài đặt > May Piano > Tài liệu bài học, or they are
 * added to the media library, from where the site moves them into its own folder and removes the library entry.
 * A file named MayPiano-DemHat-Bai-<slug>.pdf goes to the handout with that slug.
 * Only a signed-in learner of the course (or someone who may edit the lesson) can download.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Every handout, keyed by course slug then handout slug. */
function maypiano_materials() {
	static $all = null;
	if ( null !== $all ) {
		return $all;
	}
	$all = array();
	foreach ( (array) glob( get_theme_file_path( 'data/tai-lieu-*.json' ) ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || empty( $data['materials_for'] ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			continue;
		}
		$course = sanitize_title( $data['materials_for'] );
		foreach ( $data['items'] as $item ) {
			if ( empty( $item['slug'] ) || empty( $item['title'] ) ) {
				continue;
			}
			$slug                    = sanitize_title( $item['slug'] );
			$all[ $course ][ $slug ] = array(
				'slug'    => $slug,
				'title'   => (string) $item['title'],
				'tone'    => isset( $item['tone'] ) ? (string) $item['tone'] : '',
				'lessons' => array_map( 'intval', isset( $item['lessons'] ) ? (array) $item['lessons'] : array() ),
			);
		}
	}
	return $all;
}

function maypiano_material_dir() {
	$up = wp_upload_dir();
	return trailingslashit( $up['basedir'] ) . 'mp-tai-lieu';
}

/** Full path of a handout's PDF, or '' when the site does not have it yet. */
function maypiano_material_file( $course, $slug ) {
	$files = (array) get_option( 'maypiano_material_files', array() );
	$key   = $course . '/' . $slug;
	if ( empty( $files[ $key ] ) ) {
		return '';
	}
	$path = trailingslashit( maypiano_material_dir() ) . basename( (string) $files[ $key ] );
	return is_readable( $path ) ? $path : '';
}

/** Which handout a file name is for: array( course, slug ), or null. */
function maypiano_material_of_name( $name ) {
	$base = sanitize_title( preg_replace( '/\.pdf$/i', '', (string) $name ) );
	$base = preg_replace( '/^maypiano-[a-z0-9]+-bai-/', '', $base );
	foreach ( maypiano_materials() as $course => $items ) {
		if ( isset( $items[ $base ] ) ) {
			return array( $course, $base );
		}
	}
	return null;
}

/** Puts one PDF into the handout folder under a name nobody can guess. */
function maypiano_material_store( $course, $slug, $from, $uploaded ) {
	$dir = maypiano_material_dir();
	wp_mkdir_p( $dir );
	if ( ! file_exists( $dir . '/index.html' ) ) {
		file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( '%PDF-' !== (string) file_get_contents( $from, false, null, 0, 5 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	$stored = $slug . '-' . strtolower( wp_generate_password( 28, false ) ) . '.pdf';
	$ok     = $uploaded ? move_uploaded_file( $from, $dir . '/' . $stored ) : copy( $from, $dir . '/' . $stored );
	if ( ! $ok ) {
		return false;
	}
	$files = (array) get_option( 'maypiano_material_files', array() );
	$key   = $course . '/' . $slug;
	if ( ! empty( $files[ $key ] ) && is_file( $dir . '/' . basename( $files[ $key ] ) ) ) {
		unlink( $dir . '/' . basename( $files[ $key ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$files[ $key ] = $stored;
	update_option( 'maypiano_material_files', $files, false );
	return true;
}

/**
 * Takes handout PDFs out of the media library: each is copied into the handout folder and its library entry,
 * whose address anyone could open, is deleted. Returns the titles taken in.
 */
function maypiano_material_collect() {
	$found = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'application/pdf',
		'posts_per_page' => 200,
		's'              => 'MayPiano',
		'fields'         => 'ids',
	) );
	$done  = array();
	$all   = maypiano_materials();
	foreach ( $found as $id ) {
		$path = (string) get_attached_file( $id );
		$hit  = $path ? maypiano_material_of_name( basename( $path ) ) : null;
		if ( ! $hit ) {
			$hit = maypiano_material_of_name( get_the_title( $id ) );
		}
		if ( ! $hit || ! is_readable( $path ) ) {
			continue;
		}
		if ( maypiano_material_store( $hit[0], $hit[1], $path, false ) ) {
			wp_delete_attachment( $id, true );
			$done[] = $all[ $hit[0] ][ $hit[1] ]['title'];
		}
	}
	return $done;
}

/** The course slug and lesson number of a lesson made from a course file, or null. */
function maypiano_material_lesson( $lesson_id ) {
	$key = (string) get_post_meta( $lesson_id, '_maypiano_key', true );
	if ( ! preg_match( '#^([a-z0-9-]+)/bai-(\d+)$#', $key, $m ) ) {
		return null;
	}
	return array( $m[1], (int) $m[2] );
}

/** Handouts of one lesson that have a file. */
function maypiano_materials_of_lesson( $lesson_id ) {
	$at = maypiano_material_lesson( $lesson_id );
	if ( ! $at ) {
		return array();
	}
	$all  = maypiano_materials();
	$mine = array();
	foreach ( isset( $all[ $at[0] ] ) ? $all[ $at[0] ] : array() as $item ) {
		if ( in_array( $at[1], $item['lessons'], true ) ) {
			$mine[] = $item;
		}
	}
	if ( ! $mine ) {
		return array();
	}
	$missing = false;
	foreach ( $mine as $item ) {
		if ( '' === maypiano_material_file( $at[0], $item['slug'] ) ) {
			$missing = true;
		}
	}
	// A listed handout without a file may be waiting in the media library. Look there, but not on every page view.
	if ( $missing && ! get_transient( 'maypiano_material_looked' ) ) {
		set_transient( 'maypiano_material_looked', 1, 10 * MINUTE_IN_SECONDS );
		maypiano_material_collect();
	}
	return array_values( array_filter( $mine, function ( $item ) use ( $at ) {
		return '' !== maypiano_material_file( $at[0], $item['slug'] );
	} ) );
}

/** May the person looking at this lesson download its handouts? Signed-in learners of the course, and editors. */
function maypiano_material_may_download( $lesson_id ) {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'edit_post', $lesson_id ) ) {
		return true;
	}
	$course_id = (int) get_post_meta( $lesson_id, '_tutor_course_id_for_lesson', true );
	return $course_id && function_exists( 'tutor_utils' ) && (bool) tutor_utils()->is_enrolled( $course_id, get_current_user_id() );
}

function maypiano_material_link( $slug, $lesson_id ) {
	return add_query_arg( array( 'mp_tl' => $slug, 'bai' => (int) $lesson_id ), home_url( '/' ) );
}

/** The download itself: /?mp_tl=<slug>&bai=<lesson id>. */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['mp_tl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$slug   = sanitize_title( wp_unslash( $_GET['mp_tl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	$lesson = isset( $_GET['bai'] ) ? absint( $_GET['bai'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$at     = $lesson && 'lesson' === get_post_type( $lesson ) ? maypiano_material_lesson( $lesson ) : null;
	$all    = maypiano_materials();
	$item   = $at && isset( $all[ $at[0] ][ $slug ] ) ? $all[ $at[0] ][ $slug ] : null;
	$path   = $item && in_array( $at[1], $item['lessons'], true ) ? maypiano_material_file( $at[0], $slug ) : '';
	nocache_headers();
	if ( '' === $path ) {
		wp_die( 'Tài liệu này hiện chưa tải được. Bạn mở lại từ trang bài học, hoặc nhắn Mây để Mây giúp nhé.', 'Không tải được tài liệu', array( 'response' => 404 ) );
	}
	if ( ! maypiano_material_may_download( $lesson ) ) {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( array( 'tk' => 'dang-nhap', 'redirect_to' => rawurlencode( maypiano_material_link( $slug, $lesson ) ) ), home_url( '/' ) ) );
			exit;
		}
		wp_die( 'Tài liệu này dành cho học viên của khóa. Bạn đăng nhập đúng tài khoản đã đăng ký khóa học rồi mở lại nhé.', 'Không tải được tài liệu', array( 'response' => 403 ) );
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="MayPiano-' . $slug . '.pdf"' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'X-Robots-Tag: noindex' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}, 1 );

/** The box on the lesson page, above the questions box. */
add_action( 'tutor_load_template_after', function ( $template ) {
	if ( 'learning-area.lesson.footer' !== $template || ! is_singular( 'lesson' ) ) {
		return;
	}
	$lesson = (int) get_queried_object_id();
	if ( ! $lesson || ! maypiano_material_may_download( $lesson ) ) {
		return;
	}
	$items = maypiano_materials_of_lesson( $lesson );
	if ( ! $items ) {
		return;
	}
	echo '<section class="mp-tl-box" id="mp-tai-lieu" aria-labelledby="mp-tl-title">';
	echo '<h2 class="mp-tl-title" id="mp-tl-title">Tài liệu của bài này</h2>';
	echo '<p class="mp-tl-lead">Bạn tải về để vừa xem vừa tập theo nhé.</p><ul class="mp-tl-list">';
	foreach ( $items as $item ) {
		echo '<li class="mp-tl-item"><span class="mp-tl-name">' . esc_html( $item['title'] );
		if ( '' !== $item['tone'] ) {
			echo ' <span class="mp-tl-tone">Tone ' . esc_html( $item['tone'] ) . '</span>';
		}
		echo '</span><a class="mp-tl-get" href="' . esc_url( maypiano_material_link( $item['slug'], $lesson ) ) . '">Tải PDF<span class="screen-reader-text">: ' . esc_html( $item['title'] ) . '</span></a></li>';
	}
	echo '</ul></section>';
}, 5 );

/** Cài đặt > May Piano > Tài liệu bài học: the owner picks the PDFs (several at once). */
add_action( 'admin_post_maypiano_material_upload', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'maypiano_material_upload' );
	$all  = maypiano_materials();
	$done = array();
	$skip = array();
	$up   = isset( $_FILES['mp_materials'] ) ? $_FILES['mp_materials'] : array( 'name' => array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( (array) $up['name'] as $i => $name ) {
		$name = sanitize_file_name( (string) $name );
		$hit  = maypiano_material_of_name( $name );
		$tmp  = (string) $up['tmp_name'][ $i ];
		if ( ! $hit || UPLOAD_ERR_OK !== (int) $up['error'][ $i ] || ! is_uploaded_file( $tmp ) || ! maypiano_material_store( $hit[0], $hit[1], $tmp, true ) ) {
			$skip[] = $name;
			continue;
		}
		$done[] = $all[ $hit[0] ][ $hit[1] ]['title'];
	}
	$done = array_merge( $done, maypiano_material_collect() );
	set_transient( 'maypiano_material_upload_note', array( $done, $skip ), 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#mp-tai-lieu' ) );
	exit;
} );

function maypiano_material_settings_section() {
	echo '<h2 id="mp-tai-lieu">Tài liệu bài học</h2>';
	$note = get_transient( 'maypiano_material_upload_note' );
	if ( is_array( $note ) ) {
		delete_transient( 'maypiano_material_upload_note' );
		if ( $note[0] ) {
			echo '<div class="notice notice-success inline"><p>Đã nhận file cho: ' . esc_html( implode( ', ', $note[0] ) ) . '.</p></div>';
		}
		if ( $note[1] ) {
			echo '<div class="notice notice-warning inline"><p>Bỏ qua (không phải PDF, hoặc tên file không khớp tài liệu nào): ' . esc_html( implode( ', ', $note[1] ) ) . '.</p></div>';
		}
	}
	$taken = maypiano_material_collect();
	if ( $taken ) {
		echo '<div class="notice notice-success inline"><p>Đã lấy từ thư viện: ' . esc_html( implode( ', ', $taken ) ) . '.</p></div>';
	}
	echo '<p>Danh sách nằm trong <code>data/tai-lieu-&lt;khóa&gt;.json</code>. Tài liệu nào có file thì học viên của khóa thấy nút tải ở đúng bài. Chọn nhiều file một lần được; tên file dạng <code>MayPiano-DemHat-Bai-&lt;slug&gt;.pdf</code> tự vào đúng tài liệu.</p>';
	echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Tài liệu</th><th>Khóa</th><th>Bài</th><th>Slug</th><th>File PDF</th></tr></thead><tbody>';
	foreach ( maypiano_materials() as $course => $items ) {
		foreach ( $items as $item ) {
			$has = '' !== maypiano_material_file( $course, $item['slug'] );
			echo '<tr><td>' . esc_html( $item['title'] . ( '' !== $item['tone'] ? ' (tone ' . $item['tone'] . ')' : '' ) ) . '</td><td><code>' . esc_html( $course ) . '</code></td><td>' . esc_html( implode( ', ', $item['lessons'] ) ) . '</td><td><code>' . esc_html( $item['slug'] ) . '</code></td><td>' . ( $has ? 'Đã có' : '<strong>Chưa có</strong>' ) . '</td></tr>';
		}
	}
	echo '</tbody></table>';
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
	wp_nonce_field( 'maypiano_material_upload' );
	echo '<input type="hidden" name="action" value="maypiano_material_upload"><input type="file" name="mp_materials[]" accept="application/pdf" multiple required> ';
	submit_button( 'Tải tài liệu lên', 'secondary', 'submit', false );
	echo '</form>';
}
