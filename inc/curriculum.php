<?php
/**
 * Builds a course's parts and lessons in Tutor LMS from the lists in data/.
 *
 * Each file in data/ is named after the course's address (data/dem-hat-piano.json is the course at /courses/dem-hat-piano).
 * The site reads a file again only when it has changed. Parts and lessons are matched by a hidden key, so reading a
 * file twice never makes doubles, and nothing is ever deleted: a lesson taken out of the file stays in the course.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The part or lesson already made for this key, or 0. */
function maypiano_curriculum_find( $type, $key ) {
	$found = get_posts( array(
		'post_type'      => $type,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_maypiano_key', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );
	return $found ? (int) $found[0] : 0;
}

/** Makes the part or lesson, or brings its title, place and order up to date. */
function maypiano_curriculum_put( $type, $key, $title, $parent, $order ) {
	$post = array(
		'post_type'   => $type,
		'post_title'  => $title,
		'post_parent' => $parent,
		'menu_order'  => $order,
		'post_status' => 'publish',
	);
	$id = maypiano_curriculum_find( $type, $key );
	if ( $id ) {
		$post['ID'] = $id;
		wp_update_post( $post );
		return $id;
	}
	$post['post_content'] = '';
	$post['post_author']  = (int) get_post_field( 'post_author', $parent );
	$id                   = wp_insert_post( $post );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	update_post_meta( $id, '_maypiano_key', $key );
	return (int) $id;
}

/** "6:19" or "1:02:03" as the hours, minutes and seconds Tutor LMS keeps for a lesson. */
function maypiano_curriculum_runtime( $length ) {
	$bits = array_map( 'intval', explode( ':', (string) $length ) );
	while ( count( $bits ) < 3 ) {
		array_unshift( $bits, 0 );
	}
	return array(
		'hours'   => sprintf( '%02d', $bits[0] ),
		'minutes' => sprintf( '%02d', $bits[1] ),
		'seconds' => sprintf( '%02d', $bits[2] ),
	);
}

/** Reads one course file. Returns how many lessons the course now has from it, null for a file that is not a course list, or a WP_Error. */
function maypiano_curriculum_build( $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'maypiano_curriculum', 'File không đọc được.' );
	}
	if ( empty( $data['course'] ) || empty( $data['topics'] ) ) {
		return null; // Not a course list (the sheet music list lives in data/ too).
	}
	$slug    = sanitize_title( $data['course'] );
	$courses = get_posts( array(
		'post_type'      => 'courses',
		'name'           => $slug,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( ! $courses ) {
		return new WP_Error( 'maypiano_curriculum', 'Chưa có khóa học ở địa chỉ ' . $slug . '.' );
	}
	$course = (int) $courses[0];
	// "publish": true in the file opens the course to visitors. Nothing here ever takes a course back down.
	if ( ! empty( $data['publish'] ) && 'publish' !== get_post_status( $course ) ) {
		wp_update_post( array( 'ID' => $course, 'post_status' => 'publish' ) );
	}
	$count  = 0;
	foreach ( array_values( $data['topics'] ) as $t => $topic ) {
		$topic_id = maypiano_curriculum_put( 'topics', $slug . '/phan-' . ( $t + 1 ), $topic['title'], $course, $t + 1 );
		if ( ! $topic_id ) {
			return new WP_Error( 'maypiano_curriculum', 'Không tạo được phần ' . ( $t + 1 ) . '.' );
		}
		foreach ( array_values( $topic['lessons'] ) as $l => $lesson ) {
			$id = maypiano_curriculum_put( 'lesson', $slug . '/bai-' . (int) $lesson['no'], $lesson['title'], $topic_id, $l + 1 );
			if ( ! $id ) {
				return new WP_Error( 'maypiano_curriculum', 'Không tạo được bài ' . (int) $lesson['no'] . '.' );
			}
			update_post_meta( $id, '_tutor_course_id_for_lesson', $course );
			// Only the length is set here. Whatever video the lesson already has is left alone.
			$video            = get_post_meta( $id, '_video', true );
			$video            = is_array( $video ) ? $video : array( 'source' => '-1' );
			$video['runtime'] = maypiano_curriculum_runtime( isset( $lesson['length'] ) ? $lesson['length'] : '' );
			// "youtube": a lesson whose video is one of Mây's public YouTube videos (a course's introduction), not a Bunny one.
			$youtube = isset( $lesson['youtube'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $lesson['youtube'] ) : '';
			if ( '' !== $youtube ) {
				$video['source']         = 'youtube';
				$video['source_youtube'] = 'https://www.youtube.com/watch?v=' . $youtube;
			}
			update_post_meta( $id, '_video', $video );
			$count++;
		}
	}
	maypiano_curriculum_tidy( $course );
	return $count;
}

/**
 * A course may still hold parts made by hand before its file existed, which then show up twice.
 * Such a part is moved to the trash (kept there 30 days) when it is only an outline: every lesson in it has no text
 * and no video. A part with anything real in it is left alone and counted, for the settings page to mention.
 */
function maypiano_curriculum_tidy( $course ) {
	$kept    = 0;
	$trashed = 0;
	$parts   = get_posts( array(
		'post_type'      => 'topics',
		'post_parent'    => $course,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );
	foreach ( $parts as $part ) {
		if ( '' !== (string) get_post_meta( $part, '_maypiano_key', true ) ) {
			continue;
		}
		$inside = get_posts( array(
			'post_type'      => array( 'lesson', 'tutor_quiz', 'tutor_assignments', 'tutor_zoom_meeting', 'tutor-google-meet' ),
			'post_parent'    => $part,
			'post_status'    => 'any',
			'posts_per_page' => -1,
		) );
		$empty  = true;
		foreach ( $inside as $item ) {
			$video = get_post_meta( $item->ID, '_video', true );
			$has   = is_array( $video ) && isset( $video['source'] ) && ! in_array( (string) $video['source'], array( '', '-1' ), true );
			if ( 'lesson' !== $item->post_type || '' !== trim( wp_strip_all_tags( $item->post_content ) ) || $has || '' !== (string) get_post_meta( $item->ID, '_maypiano_key', true ) ) {
				$empty = false;
				break;
			}
		}
		if ( ! $empty ) {
			$kept++;
			continue;
		}
		foreach ( $inside as $item ) {
			wp_trash_post( $item->ID );
		}
		wp_trash_post( $part );
		$trashed++;
	}
	// Tutor LMS counts a course's lessons by this link, trash included. Lessons in the trash must not be counted.
	$binned = get_posts( array(
		'post_type'      => 'lesson',
		'post_status'    => 'trash',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_tutor_course_id_for_lesson', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => (string) $course, // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
	foreach ( $binned as $lesson ) {
		delete_post_meta( $lesson, '_tutor_course_id_for_lesson' );
	}
	// Tutor LMS also counts every lesson under every part that still names this course as its parent, trash included.
	// A part in the trash is therefore let go of the course. It stays in the trash, with its lessons, for 30 days.
	$binned_parts = get_posts( array(
		'post_type'      => 'topics',
		'post_parent'    => $course,
		'post_status'    => 'trash',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );
	foreach ( $binned_parts as $part ) {
		update_post_meta( $part, '_maypiano_was_in', $course );
		wp_update_post( array( 'ID' => $part, 'post_parent' => 0 ) );
	}
	$previous = get_post_meta( $course, '_maypiano_tidy', true );
	if ( is_array( $previous ) ) {
		$trashed += (int) $previous['trashed'];
	}
	update_post_meta( $course, '_maypiano_tidy', array( 'trashed' => $trashed, 'kept' => $kept ) );
}

/** Reads every course file that has changed since the site last read it. */
add_action( 'init', function () {
	if ( ! post_type_exists( 'courses' ) || ! post_type_exists( 'lesson' ) ) {
		return;
	}
	$files = glob( get_theme_file_path( 'data/*.json' ) );
	if ( ! $files ) {
		return;
	}
	$seen = (array) get_option( 'maypiano_curriculum', array() );
	foreach ( $files as $file ) {
		$name  = basename( $file, '.json' );
		$stamp = MAYPIANO_VERSION . '-' . filesize( $file ) . '-' . md5_file( $file );
		if ( isset( $seen[ $name ]['stamp'] ) && $seen[ $name ]['stamp'] === $stamp ) {
			continue;
		}
		if ( get_transient( 'maypiano_curriculum_busy' ) ) {
			return;
		}
		set_transient( 'maypiano_curriculum_busy', 1, 5 * MINUTE_IN_SECONDS );
		$result        = maypiano_curriculum_build( $file );
		$seen[ $name ] = array(
			'stamp'  => $stamp,
			'at'     => time(),
			'result' => is_wp_error( $result ) ? $result->get_error_message() : ( null === $result ? 'skip' : (int) $result ),
		);
		update_option( 'maypiano_curriculum', $seen, false );
		delete_transient( 'maypiano_curriculum_busy' );
	}
}, 99 );

/** For the settings page: one line per course file, saying what happened the last time it was read. */
function maypiano_curriculum_report() {
	$lines = array();
	foreach ( (array) get_option( 'maypiano_curriculum', array() ) as $name => $row ) {
		if ( 'skip' === $row['result'] ) {
			continue;
		}
		$line   = $name . ': ' . ( is_int( $row['result'] ) ? $row['result'] . ' bài' : 'LỖI. ' . $row['result'] ) . ', đọc lúc ' . wp_date( 'd/m/Y H:i', (int) $row['at'] );
		$course = get_posts( array( 'post_type' => 'courses', 'name' => sanitize_title( $name ), 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$tidy   = $course ? get_post_meta( $course[0], '_maypiano_tidy', true ) : '';
		if ( is_array( $tidy ) && ( $tidy['trashed'] || $tidy['kept'] ) ) {
			$line .= '. Phần cũ bị trùng: đã đưa ' . (int) $tidy['trashed'] . ' phần trống vào thùng rác, còn ' . (int) $tidy['kept'] . ' phần có nội dung chưa đụng tới';
		}
		$lines[] = $line;
	}
	return $lines;
}

/** For the owner only: what a course really holds, as plain numbers. /wp-admin/admin-post.php?action=maypiano_count&course=ID */
add_action( 'admin_post_maypiano_count', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Không có quyền.' );
	}
	global $wpdb;
	$course = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$out    = array( 'course' => $course, 'version' => MAYPIANO_VERSION, 'tidy' => get_post_meta( $course, '_maypiano_tidy', true ) );
	// phpcs:disable WordPress.DB
	$out['parts_by_parent']   = $wpdb->get_results( $wpdb->prepare( "SELECT post_status s, COUNT(*) n FROM {$wpdb->posts} WHERE post_type='topics' AND post_parent=%d GROUP BY post_status", $course ), ARRAY_A );
	$out['lessons_by_part']   = $wpdb->get_results( $wpdb->prepare( "SELECT t.post_status part, l.post_status lesson, l.post_type type, COUNT(*) n FROM {$wpdb->posts} t JOIN {$wpdb->posts} l ON l.post_parent=t.ID WHERE t.post_type='topics' AND t.post_parent=%d GROUP BY t.post_status, l.post_status, l.post_type", $course ), ARRAY_A );
	$out['lessons_by_link']   = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_status s, p.post_type type, (SELECT pp.post_status FROM {$wpdb->posts} pp WHERE pp.ID=p.post_parent) parent_status, (SELECT pp.post_parent FROM {$wpdb->posts} pp WHERE pp.ID=p.post_parent) grandparent, COUNT(*) n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_tutor_course_id_for_lesson' AND m.meta_value=%s GROUP BY 1,2,3,4", (string) $course ), ARRAY_A );
	$out['keyed']             = $wpdb->get_results( $wpdb->prepare( "SELECT p.post_type type, p.post_status s, COUNT(*) n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_maypiano_key' AND m.meta_value LIKE %s GROUP BY 1,2", $wpdb->esc_like( (string) get_post_field( 'post_name', $course ) ) . '/%' ), ARRAY_A );
	// phpcs:enable
	if ( function_exists( 'tutor_utils' ) ) {
		$out['tutor_count'] = tutor_utils()->get_lesson_count_by_course( $course );
	}
	nocache_headers();
	wp_send_json( $out );
} );
