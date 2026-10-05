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
