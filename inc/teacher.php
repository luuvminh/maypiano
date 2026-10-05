<?php
/**
 * The teacher's account. Every course, part and lesson belongs to it, so learners see Mây as the one who teaches.
 * The account is made from the email in Settings > May Piano. No invitation is sent; whoever holds that email can
 * choose a password from the sign-in page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_TEACHER_NAME = 'Mây';

/** Makes (or finds) the teacher's account for this email and hands it every course. Returns a line to show, or a WP_Error. */
function maypiano_teacher_set( $email ) {
	global $wpdb;
	$email = sanitize_email( $email );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'maypiano_teacher', 'Email người dạy không hợp lệ.' );
	}
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		$login = 'may';
		while ( username_exists( $login ) ) {
			$login = 'may' . wp_rand( 100, 999 );
		}
		$id = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 40, true, true ),
			'display_name' => MAYPIANO_TEACHER_NAME,
			'nickname'     => MAYPIANO_TEACHER_NAME,
			'first_name'   => MAYPIANO_TEACHER_NAME,
			'role'         => get_role( 'tutor_instructor' ) ? 'tutor_instructor' : 'author',
		) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$user = get_user_by( 'id', $id );
	} else {
		if ( get_role( 'tutor_instructor' ) && ! user_can( $user, 'manage_options' ) ) {
			$user->add_role( 'tutor_instructor' );
		}
		wp_update_user( array( 'ID' => $user->ID, 'display_name' => MAYPIANO_TEACHER_NAME, 'nickname' => MAYPIANO_TEACHER_NAME ) );
	}
	$teacher = (int) $user->ID;
	update_user_meta( $teacher, '_is_tutor_instructor', time() );
	update_user_meta( $teacher, '_tutor_instructor_status', 'approved' );

	$types  = array( 'courses', 'topics', 'lesson', 'tutor_quiz', 'tutor_assignments' );
	$marks  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$moved  = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_author = %d WHERE post_author <> %d AND post_type IN ($marks)", array_merge( array( $teacher, $teacher ), $types ) ) ); // phpcs:ignore WordPress.DB
	$linked = array_map( 'intval', (array) get_user_meta( $teacher, '_tutor_instructor_course_id' ) );
	$total  = 0;
	foreach ( get_posts( array( 'post_type' => 'courses', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $course ) {
		$total++;
		clean_post_cache( $course );
		if ( ! in_array( (int) $course, $linked, true ) ) {
			add_user_meta( $teacher, '_tutor_instructor_course_id', (int) $course );
		}
	}
	return 'Người dạy của ' . $total . ' khóa là ' . MAYPIANO_TEACHER_NAME . ' (tài khoản ' . $user->user_login . '). Lần này chuyển ' . $moved . ' mục.';
}

add_action( 'admin_init', function () {
	register_setting( 'maypiano', 'maypiano_teacher_email', array( 'sanitize_callback' => 'sanitize_email' ) );
} );

/** The teacher part of Settings > May Piano. Opening the page keeps every course with the teacher, new ones included. */
function maypiano_teacher_settings_section() {
	$email = trim( (string) get_option( 'maypiano_teacher_email', '' ) );
	echo '<h2>Người dạy</h2>';
	if ( '' !== $email ) {
		$result = maypiano_teacher_set( $email );
		echo '<p><strong>' . esc_html( is_wp_error( $result ) ? 'LỖI. ' . $result->get_error_message() : $result ) . '</strong></p>';
	}
	echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="maypiano_teacher_email">Email của Mây</label></th><td>';
	echo '<input type="email" class="regular-text" id="maypiano_teacher_email" name="maypiano_teacher_email" value="' . esc_attr( $email ) . '">';
	echo '<p class="description">Site tạo một tài khoản người dạy tên Mây với email này và giao mọi khóa học cho tài khoản đó. Không có email mời nào được gửi.</p></td></tr></table>';
}
