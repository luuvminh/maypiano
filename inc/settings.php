<?php
/**
 * Settings > May Piano: where the money goes, what each course costs, and how card payments behave.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plain text settings: key => array( label, help, default ). */
function maypiano_fields() {
	return array(
		'maypiano_bank_bin'     => array( 'Mã ngân hàng (BIN)', 'Techcombank là 970407. Chọn ảnh mã QR ở trên để tự điền.', '970407' ),
		'maypiano_account'      => array( 'Số tài khoản nhận tiền', 'Có số này thì trang thanh toán tự tạo mã QR có sẵn số tiền và nội dung cho từng đơn.', '' ),
		'maypiano_account_name' => array( 'Tên chủ tài khoản', 'Viết hoa, không dấu, đúng như ngân hàng ghi.', 'PHAN TRAN HAI MAY' ),
		'maypiano_qr'           => array( 'Link ảnh mã QR có sẵn', 'Chỉ dùng khi chưa điền số tài khoản. Ảnh này không kèm số tiền.', '' ),
		'maypiano_paypal'       => array( 'PayPal', 'Tên PayPal.Me (ví dụ: maypiano) hoặc email PayPal. Để trống thì Mây gửi cách trả qua email.', '' ),
		'maypiano_processing'   => array( 'Thời gian kích hoạt tài khoản học', 'Ví dụ: 24 giờ.', '24 giờ' ),
		'maypiano_support'      => array( 'Cách liên hệ khi cần giúp', 'Hiện cho khách ở bước thanh toán. Ví dụ: Zalo 09xx hoặc một email.', '' ),
		'maypiano_notify'       => array( 'Email nhận thông báo đơn mới', 'Người nhận email này duyệt được đơn bằng một nút bấm. Nhiều email thì cách nhau bằng dấu phẩy. Để trống thì dùng email quản trị của site.', '' ),
	);
}

function maypiano_setting( $key ) {
	$fields  = maypiano_fields();
	$default = isset( $fields[ $key ] ) ? $fields[ $key ][2] : '';
	$value   = trim( (string) get_option( $key, $default ) );
	return '' === $value ? $default : $value;
}

/** How paying by card behaves: off, test (only the owner sees it, no money moves) or live. */
function maypiano_card_mode() {
	$mode = get_option( 'maypiano_card_mode', 'test' );
	return in_array( $mode, array( 'off', 'test', 'live' ), true ) ? $mode : 'test';
}

/** Secret for the test link. Made once; anyone holding the link can rehearse a card payment as a guest. */
function maypiano_test_key() {
	$key = (string) get_option( 'maypiano_test_key', '' );
	if ( '' === $key ) {
		$key = wp_generate_password( 20, false );
		update_option( 'maypiano_test_key', $key, false );
	}
	return $key;
}

function maypiano_test_url() {
	return add_query_arg( 'thu', maypiano_test_key(), home_url( '/dang-ky/' ) );
}

/** May this visitor rehearse a card payment? The owner, or a guest who opened the test link. */
function maypiano_can_test() {
	if ( 'test' !== maypiano_card_mode() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$given = isset( $_GET['thu'] ) ? $_GET['thu'] : ( isset( $_COOKIE['mp_thu'] ) ? $_COOKIE['mp_thu'] : '' );
	return is_string( $given ) && '' !== $given && hash_equals( maypiano_test_key(), sanitize_text_field( wp_unslash( $given ) ) );
}

/** Opening the test link remembers it for a day, so the whole sign-up flow can be rehearsed without signing in. */
add_action( 'init', function () {
	if ( isset( $_GET['thu'] ) && ! isset( $_COOKIE['mp_thu'] ) && ! headers_sent() && maypiano_can_test() ) {
		setcookie( 'mp_thu', maypiano_test_key(), time() + DAY_IN_SECONDS, '/', '', is_ssl(), true );
	}
} );

function maypiano_sanitize_prices( $input ) {
	$clean = array();
	foreach ( maypiano_catalog() as $key => $course ) {
		foreach ( maypiano_regions() as $currency ) {
			$raw = isset( $input[ $key ][ $currency ] ) ? preg_replace( '/[^0-9.]/', '', str_replace( ',', '', (string) $input[ $key ][ $currency ] ) ) : '';
			if ( 'VND' === $currency ) {
				$raw = str_replace( '.', '', $raw );
			}
			if ( '' !== $raw && (float) $raw > 0 ) {
				$clean[ $key ][ $currency ] = (float) $raw;
			}
		}
	}
	return $clean;
}

function maypiano_sanitize_products( $input ) {
	$clean = array();
	foreach ( maypiano_catalog() as $key => $course ) {
		if ( ! empty( $input[ $key ] ) ) {
			$clean[ $key ] = absint( $input[ $key ] );
		}
	}
	return $clean;
}

add_action( 'admin_init', function () {
	foreach ( maypiano_fields() as $key => $field ) {
		register_setting( 'maypiano', $key, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}
	register_setting( 'maypiano', 'maypiano_card_mode', array( 'sanitize_callback' => 'sanitize_key' ) );
	register_setting( 'maypiano', 'maypiano_prices', array( 'sanitize_callback' => 'maypiano_sanitize_prices' ) );
	register_setting( 'maypiano', 'maypiano_products', array( 'sanitize_callback' => 'maypiano_sanitize_products' ) );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_maypiano' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'maypiano-jsqr', get_theme_file_uri( 'assets/js/jsqr.js' ), array(), MAYPIANO_VERSION, true );
	wp_enqueue_script( 'maypiano-vietqr', get_theme_file_uri( 'assets/js/vietqr.js' ), array(), MAYPIANO_VERSION, true );
	wp_enqueue_script( 'maypiano-admin', get_theme_file_uri( 'assets/js/admin.js' ), array( 'maypiano-jsqr', 'maypiano-vietqr' ), MAYPIANO_VERSION, true );
} );

function maypiano_settings_row( $key, $field ) {
	printf(
		'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input class="regular-text" type="text" id="%1$s" name="%1$s" value="%3$s"><p class="description">%4$s</p></td></tr>',
		esc_attr( $key ),
		esc_html( $field[0] ),
		esc_attr( get_option( $key, $field[2] ) ),
		esc_html( $field[1] )
	);
}

add_action( 'admin_menu', function () {
	add_options_page( 'May Piano', 'May Piano', 'manage_options', 'maypiano', 'maypiano_settings_page' );
} );

function maypiano_settings_page() {
	$fields   = maypiano_fields();
	$prices   = (array) get_option( 'maypiano_prices', array() );
	$picked   = (array) get_option( 'maypiano_products', array() );
	$has_wc   = function_exists( 'wc_get_orders' );
	$has_lms  = function_exists( 'tutor_utils' );
	$mode     = maypiano_card_mode();
	$bank     = array( 'maypiano_bank_bin', 'maypiano_account', 'maypiano_account_name', 'maypiano_qr' );
	$other    = array( 'maypiano_paypal', 'maypiano_processing', 'maypiano_support', 'maypiano_notify' );

	echo '<div class="wrap"><h1>May Piano</h1>';
	if ( ! $has_wc ) {
		echo '<div class="notice notice-error"><p>WooCommerce đang tắt. Trang đăng ký không nhận được đơn cho tới khi bật lại.</p></div>';
	}
	if ( ! $has_lms ) {
		echo '<div class="notice notice-warning"><p>Tutor LMS đang tắt. Đơn vẫn được ghi, nhưng học viên không tự được cấp quyền học.</p></div>';
	}
	foreach ( maypiano_curriculum_report() as $line ) {
		echo '<div class="notice notice-info inline"><p>Nội dung khóa học đã dựng từ file. ' . esc_html( $line ) . '</p></div>';
	}
	echo '<form method="post" action="options.php">';
	settings_fields( 'maypiano' );

	echo '<h2>Chuyển khoản ngân hàng (khách ở Việt Nam)</h2>';
	echo '<p><input type="file" id="mp-qr-file" accept="image/*"> <span id="mp-qr-result" class="description">Đọc từ ảnh mã QR: chọn ảnh mã QR của ngân hàng, ba ô bên dưới sẽ tự điền. Ảnh chỉ được đọc trên máy của bạn.</span></p>';
	echo '<table class="form-table" role="presentation">';
	foreach ( $bank as $key ) {
		maypiano_settings_row( $key, $fields[ $key ] );
	}
	echo '</table>';

	echo '<h2>Thanh toán bằng thẻ</h2><table class="form-table" role="presentation"><tr><th scope="row">Chế độ</th><td>';
	$modes = array(
		'off'  => 'Tắt. Khách không thấy lựa chọn trả bằng thẻ.',
		'test' => 'Chạy thử. Quản trị viên đang đăng nhập thấy, và khách mở đường dẫn chạy thử bên dưới cũng thấy. Không có tiền thật, không nhập số thẻ.',
		'live' => 'Thật. Khách được chuyển sang trang trả tiền của WooCommerce. Chỉ bật khi cổng Stripe đã cài xong.',
	);
	foreach ( $modes as $value => $label ) {
		printf( '<p><label><input type="radio" name="maypiano_card_mode" value="%s"%s> %s</label></p>', esc_attr( $value ), checked( $mode, $value, false ), esc_html( $label ) );
	}
	if ( 'test' === $mode ) {
		echo '<p><strong>Đường dẫn chạy thử cho khách chưa đăng nhập:</strong><br><code>' . esc_html( maypiano_test_url() ) . '</code></p>';
		echo '<p class="description">Mở đường dẫn này trong cửa sổ ẩn danh để thử như một khách mới. Dùng một email chưa có tài khoản. Ai có đường dẫn này đều mở được khóa học mà không trả tiền, nên đừng gửi ra ngoài.</p>';
	}
	echo '</td></tr></table>';

	echo '<h2>Bảng giá và sản phẩm</h2>';
	echo '<p class="description">Ô VND để trống thì lấy giá của sản phẩm WooCommerce. Ô AUD hoặc USD để trống thì khách ở vùng đó đặt đơn được, nhưng Mây phải báo giá qua email.</p>';
	echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Khóa học</th><th>VND</th><th>AUD</th><th>USD</th><th>Sản phẩm WooCommerce</th><th>Khóa Tutor LMS</th></tr></thead><tbody>';
	foreach ( maypiano_catalog() as $key => $course ) {
		echo '<tr><td><strong>' . esc_html( $course['name'] ) . '</strong></td>';
		foreach ( maypiano_regions() as $currency ) {
			$stored = isset( $prices[ $key ][ $currency ] ) ? $prices[ $key ][ $currency ] : '';
			$live   = maypiano_price( $key, $currency );
			printf(
				'<td><input type="text" inputmode="decimal" size="10" name="maypiano_prices[%s][%s]" value="%s" placeholder="%s"></td>',
				esc_attr( $key ),
				esc_attr( $currency ),
				esc_attr( '' === $stored ? '' : (string) ( 0 + $stored ) ),
				esc_attr( null === $live ? 'chưa có' : (string) ( 0 + $live ) )
			);
		}
		$product = maypiano_product_for( $key );
		echo '<td><select name="maypiano_products[' . esc_attr( $key ) . ']"><option value="0">Tự tìm theo tên' . ( $product && empty( $picked[ $key ] ) ? ': ' . esc_html( $product->get_name() ) : '' ) . '</option>';
		foreach ( maypiano_all_products() as $option ) {
			printf( '<option value="%d"%s>%s (#%d, %s)</option>', (int) $option->get_id(), selected( ! empty( $picked[ $key ] ) ? (int) $picked[ $key ] : 0, $option->get_id(), false ), esc_html( $option->get_name() ), (int) $option->get_id(), esc_html( $option->get_status() ) );
		}
		echo '</select>' . ( $product ? '' : '<p class="description" style="color:#b32d2e">Chưa có sản phẩm khớp. Đơn vẫn ghi được.</p>' ) . '</td>';
		$course_id = maypiano_course_for( $key, $product ? $product->get_id() : 0 );
		echo '<td>';
		if ( ! $course_id ) {
			echo '<span style="color:#b32d2e">Chưa tìm thấy khóa. Khách trả tiền xong sẽ không tự vào học được.</span>';
		} else {
			echo esc_html( get_the_title( $course_id ) ) . ' (#' . (int) $course_id . ', ' . esc_html( get_post_status( $course_id ) ) . ')';
			if ( ! in_array( get_post_status( $course_id ), array( 'publish', 'private' ), true ) ) {
				echo '<p class="description" style="color:#b32d2e">Khóa còn là bản nháp. Tutor LMS không cho ghi danh vào bản nháp: hãy đăng khóa trước khi bán.</p>';
			}
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<h2>Khác</h2><table class="form-table" role="presentation">';
	foreach ( $other as $key ) {
		maypiano_settings_row( $key, $fields[ $key ] );
	}
	echo '</table>';
	maypiano_bunny_settings_section();
	maypiano_teacher_settings_section();
	submit_button();
	echo '</form>';
	maypiano_enrol_by_hand_section();
	maypiano_sheet_settings_section();
	maypiano_material_settings_section();
	maypiano_song_settings_section();
	maypiano_fx_settings_section();
	echo '</div>';
}
