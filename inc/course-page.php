<?php
/**
 * The page of one course, in the May Piano look, in place of the Tutor LMS one.
 * Parts and lessons come from Tutor LMS. The words about the course come from its file in data/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** True when a signed-in learner opens one of the course's learner pages (questions, announcements...), which Tutor LMS draws. */
function maypiano_course_subpage() {
	return is_user_logged_in() && isset( $_GET['subpage'] ) && '' !== $_GET['subpage']; // phpcs:ignore WordPress.Security.NonceVerification
}

add_filter( 'template_include', function ( $template ) {
	if ( is_singular( 'courses' ) && ! is_admin() && ! maypiano_course_subpage() ) {
		$ours = get_theme_file_path( 'single-courses.php' );
		if ( is_readable( $ours ) ) {
			return $ours;
		}
	}
	return $template;
}, 999 );

/** The owner and the teacher can open every lesson without being enrolled, whatever the Tutor LMS setting says. */
add_filter( 'option_tutor_option', function ( $options ) {
	if ( is_array( $options ) ) {
		$options['course_content_access_for_ia'] = 'on';
	}
	return $options;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_singular( 'courses' ) && ! maypiano_course_subpage() ) {
		wp_enqueue_style( 'maypiano-course', get_theme_file_uri( 'assets/css/course.css' ), array( 'maypiano-app' ), MAYPIANO_VERSION );
	}
	// Every page Tutor LMS draws for a learner: the lesson page, quizzes and assignments, and the learner's own pages.
	if ( maypiano_chrome_needed() ) {
		wp_enqueue_style( 'maypiano-lesson', get_theme_file_uri( 'assets/css/lesson.css' ), array( 'maypiano-app' ), MAYPIANO_VERSION );
	}
}, 100 );

/** Seconds as the site says a length: "3 giờ 55 phút", "21 phút". */
function maypiano_length_words( $seconds ) {
	$minutes = (int) round( $seconds / 60 );
	$hours   = intdiv( $minutes, 60 );
	$rest    = $minutes % 60;
	if ( $hours && $rest ) {
		return $hours . ' giờ ' . sprintf( '%02d', $rest ) . ' phút';
	}
	return $hours ? $hours . ' giờ' : $minutes . ' phút';
}

/** Everything the course page shows. */
function maypiano_course_view( $course_id ) {
	$slug = (string) get_post_field( 'post_name', $course_id );
	$file = get_theme_file_path( 'data/' . $slug . '.json' );
	$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$data = is_array( $data ) ? $data : array();

	$enrolled = is_user_logged_in() && function_exists( 'tutor_utils' ) && (bool) tutor_utils()->is_enrolled( $course_id, get_current_user_id() );
	$inside   = $enrolled || current_user_can( 'edit_post', $course_id );

	$parts   = array();
	$lessons = 0;
	$seconds = 0;
	$first   = '';
	$topics  = get_posts( array(
		'post_type'      => 'topics',
		'post_parent'    => $course_id,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	) );
	foreach ( $topics as $topic ) {
		$rows  = array();
		$total = 0;
		$items = get_posts( array(
			'post_type'      => 'lesson',
			'post_parent'    => $topic->ID,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		) );
		foreach ( $items as $item ) {
			$video = get_post_meta( $item->ID, '_video', true );
			$run   = is_array( $video ) && isset( $video['runtime'] ) ? (array) $video['runtime'] : array();
			$secs  = 3600 * (int) ( $run['hours'] ?? 0 ) + 60 * (int) ( $run['minutes'] ?? 0 ) + (int) ( $run['seconds'] ?? 0 );
			$free  = (bool) get_post_meta( $item->ID, '_is_preview', true );
			$open  = $inside || $free;
			$url   = $open ? get_permalink( $item->ID ) : '';
			if ( '' === $first && $inside ) {
				$first = $url;
			}
			$rows[] = array(
				'no'     => ++$lessons,
				'title'  => get_the_title( $item ),
				'length' => $secs ? sprintf( '%d:%02d', intdiv( $secs, 60 ), $secs % 60 ) : '',
				'url'    => $url,
				'free'   => $free && ! $inside,
			);
			$total += $secs;
		}
		$seconds += $total;
		$parts[]  = array(
			'title'   => get_the_title( $topic ),
			'lessons' => $rows,
			'meta'    => count( $rows ) . ' bài' . ( $total ? ', ' . maypiano_length_words( $total ) : '' ),
		);
	}

	$key   = maypiano_key_for_post( $course_id );
	$price = '' !== $key ? maypiano_price( $key, 'VND' ) : null;
	$photo = isset( $data['photo'] ) ? sanitize_file_name( $data['photo'] ) : 'g04.jpg';

	return array(
		'title'    => get_the_title( $course_id ),
		'intro'    => isset( $data['intro'] ) ? (string) $data['intro'] : wp_strip_all_tags( get_the_excerpt( $course_id ) ),
		'gets'     => isset( $data['gets'] ) ? array_map( 'strval', (array) $data['gets'] ) : array(),
		'photo'    => get_theme_file_uri( 'assets/img/' . $photo ),
		'alt'      => isset( $data['photo_alt'] ) ? (string) $data['photo_alt'] : 'Mây bên cây đàn piano',
		'parts'    => $parts,
		'meta'     => $lessons ? $lessons . ' bài' . ( $seconds ? ', ' . maypiano_length_words( $seconds ) : '' ) : '',
		'key'      => $key,
		'price'    => null === $price ? '' : maypiano_money( $price, 'VND' ),
		'enrolled' => $inside,
		'first'    => $first,
		'buy'      => maypiano_signup_url( $key ),
	);
}

/**
 * For the home page: the courses the signed-in visitor can study, each with the address of every lesson in order.
 * Empty for a visitor who is not signed in or has no course, so the home page stays as it is for them.
 */
function maypiano_my_courses() {
	if ( ! is_user_logged_in() || ! function_exists( 'tutor_utils' ) ) {
		return array();
	}
	$mine = array();
	foreach ( array_keys( maypiano_catalog() ) as $key ) {
		$course_id = maypiano_course_for( $key );
		if ( ! $course_id ) {
			continue;
		}
		$enrolled = (bool) tutor_utils()->is_enrolled( $course_id, get_current_user_id() );
		if ( ! $enrolled && ! current_user_can( 'edit_post', $course_id ) ) {
			continue;
		}
		$lessons = array();
		$topics  = get_posts( array(
			'post_type'      => 'topics',
			'post_parent'    => $course_id,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );
		foreach ( $topics as $topic_id ) {
			$items = get_posts( array(
				'post_type'      => 'lesson',
				'post_parent'    => $topic_id,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
			) );
			foreach ( $items as $item ) {
				$lessons[] = array( html_entity_decode( get_the_title( $item ), ENT_QUOTES, 'UTF-8' ), esc_url_raw( get_permalink( $item->ID ) ) );
			}
		}
		$mine[ $key ] = array(
			'enrolled' => $enrolled,
			'go'       => $lessons ? $lessons[0][1] : esc_url_raw( get_permalink( $course_id ) ),
			'lessons'  => $lessons,
		);
	}
	return $mine;
}

/** A course kept private still reads as its own name to the learner, without WordPress's "Riêng tư:" in front. */
add_filter( 'private_title_format', function ( $format, $post = null ) {
	return ( $post && in_array( get_post_type( $post ), array( 'courses', 'topics', 'lesson' ), true ) && ! is_admin() ) ? '%s' : $format;
}, 10, 2 );
