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

/** Reads one course file. Returns how many lessons the course now has from it, or a WP_Error. */
function maypiano_curriculum_build( $file ) {
	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( empty( $data['course'] ) || empty( $data['topics'] ) ) {
		return new WP_Error( 'maypiano_curriculum', 'File không đọc được.' );
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
			update_post_meta( $id, '_video', $video );
			$count++;
		}
	}
	return $count;
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
		$stamp = filesize( $file ) . '-' . md5_file( $file );
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
			'result' => is_wp_error( $result ) ? $result->get_error_message() : (int) $result,
		);
		update_option( 'maypiano_curriculum', $seen, false );
		delete_transient( 'maypiano_curriculum_busy' );
	}
}, 99 );

/** For the settings page: one line per course file, saying what happened the last time it was read. */
function maypiano_curriculum_report() {
	$lines = array();
	foreach ( (array) get_option( 'maypiano_curriculum', array() ) as $name => $row ) {
		$lines[] = $name . ': ' . ( is_int( $row['result'] ) ? $row['result'] . ' bài' : 'LỖI. ' . $row['result'] ) . ', đọc lúc ' . wp_date( 'd/m/Y H:i', (int) $row['at'] );
	}
	return $lines;
}
