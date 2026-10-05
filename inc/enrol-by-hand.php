<?php
/**
 * Lets the owner put someone on a course by hand, from Settings > May Piano: a gift, a tester, or a buyer whose
 * order could not open the course. The account is made the same way an approved order makes one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_maypiano_enrol_by_hand', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Không có quyền.' );
	}
	check_admin_referer( 'maypiano_enrol_by_hand' );
	$name   = isset( $_POST['mp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['mp_name'] ) ) : '';
	$email  = isset( $_POST['mp_email'] ) ? sanitize_email( wp_unslash( $_POST['mp_email'] ) ) : '';
	$keys   = isset( $_POST['mp_course'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['mp_course'] ) ) : array();
	$note   = array( 'ok' => false, 'text' => '', 'link' => '' );
	$courses = array();
	foreach ( array_intersect( $keys, array_keys( maypiano_catalog() ) ) as $key ) {
		$product = maypiano_product_for( $key );
		$found   = maypiano_course_for( $key, $product ? $product->get_id() : 0 );
		if ( $found ) {
			$courses[] = $found;
		}
	}

	if ( ! is_email( $email ) ) {
		$note['text'] = 'Email không hợp lệ.';
	} elseif ( ! $courses || ! function_exists( 'tutor_utils' ) ) {
		$note['text'] = 'Bạn chưa chọn khóa nào, hoặc khóa đã chọn chưa có trên site.';
	} else {
		$user = get_user_by( 'email', $email );
		$new  = false;
		if ( ! $user ) {
			if ( function_exists( 'wc_create_new_customer' ) ) {
				add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
				$id = wc_create_new_customer( $email, wc_create_new_customer_username( $email ), wp_generate_password( 24 ), array( 'first_name' => $name, 'display_name' => '' !== $name ? $name : $email ) );
				remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
			} else {
				$id = wp_insert_user( array( 'user_login' => sanitize_user( strtok( $email, '@' ), true ) . wp_rand( 100, 999 ), 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'display_name' => '' !== $name ? $name : $email, 'first_name' => $name, 'role' => 'subscriber' ) );
			}
			$user = is_wp_error( $id ) ? null : get_user_by( 'id', $id );
			$new  = true;
			if ( ! $user ) {
				$note['text'] = 'Không tạo được tài khoản: ' . ( is_wp_error( $id ) ? $id->get_error_message() : 'lỗi không rõ' );
			}
		}
		if ( $user ) {
			$opened = array();
			$failed = array();
			foreach ( $courses as $course ) {
				if ( maypiano_enrol( $course, (int) $user->ID, 0, true ) ) {
					$opened[] = get_the_title( $course );
				} else {
					$failed[] = get_the_title( $course );
				}
			}
			if ( $opened ) {
				$note['ok']   = true;
				$note['text'] = ( $new ? 'Đã tạo tài khoản và mở' : 'Tài khoản đã có sẵn, đã mở' ) . ' cho ' . $user->display_name . ' (' . $email . '): ' . implode( ', ', $opened ) . '.' . ( $failed ? ' KHÔNG mở được: ' . implode( ', ', $failed ) . '.' : '' );
				$note['link'] = (string) maypiano_password_url( $user );
			} else {
				$note['text'] = 'Có tài khoản nhưng không mở được khóa nào: ' . implode( ', ', $failed ) . '.';
			}
		}
	}
	set_transient( 'maypiano_enrol_note_' . get_current_user_id(), $note, 10 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#ghi-danh-tay' ) );
	exit;
} );

function maypiano_enrol_by_hand_section() {
	$note = get_transient( 'maypiano_enrol_note_' . get_current_user_id() );
	delete_transient( 'maypiano_enrol_note_' . get_current_user_id() );
	echo '<h2 id="ghi-danh-tay">Ghi danh tay</h2>';
	echo '<p class="description">Mở một khóa cho một người mà không cần đơn hàng. Chưa có tài khoản thì site tạo luôn. Site không tự gửi email; bạn gửi cho họ đường dẫn đặt mật khẩu hiện ra sau khi bấm.</p>';
	if ( is_array( $note ) && '' !== $note['text'] ) {
		echo '<div class="notice inline ' . ( $note['ok'] ? 'notice-success' : 'notice-error' ) . '"><p><strong>' . esc_html( $note['text'] ) . '</strong></p>';
		if ( '' !== $note['link'] ) {
			echo '<p>Đường dẫn đặt mật khẩu, dùng được trong 24 giờ:<br><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr( $note['link'] ) . '"></p>';
		}
		echo '</div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="maypiano_enrol_by_hand">';
	wp_nonce_field( 'maypiano_enrol_by_hand' );
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="mp_name">Họ tên</label></th><td><input type="text" class="regular-text" id="mp_name" name="mp_name" required></td></tr>';
	echo '<tr><th scope="row"><label for="mp_email">Email</label></th><td><input type="email" class="regular-text" id="mp_email" name="mp_email" required></td></tr>';
	echo '<tr><th scope="row">Khóa học</th><td><fieldset>';
	foreach ( maypiano_catalog() as $key => $course ) {
		echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="mp_course[]" value="' . esc_attr( $key ) . '"> ' . esc_html( $course['name'] ) . '</label>';
	}
	echo '<p class="description">Tích một hay nhiều khóa. Người đã có khóa nào thì khóa đó giữ nguyên.</p></fieldset></td></tr></table>';
	submit_button( 'Mở các khóa đã tích cho người này', 'secondary' );
	echo '</form>';
}
