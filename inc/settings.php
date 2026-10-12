<?php
/**
 * Settings > May Piano: where the money goes, what each course costs, and how card payments behave.
 * The page is split into tabs. Each tab does only its own work and saves only its own boxes.
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

/**
 * The tabs of Settings > May Piano: slug => array( label, list of array( what is there, anchor, extra words to find it by ) ).
 * A new block goes into the tab it belongs to here, and into maypiano_settings_page() below.
 */
function maypiano_settings_tabs() {
	return array(
		'tong-quan'  => array( 'Tổng quan', array() ),
		'thanh-toan' => array( 'Thanh toán', array(
			array( 'Chuyển khoản ngân hàng: số tài khoản, mã QR', 'chuyen-khoan', 'techcombank bin vietqr ngan hang tai khoan' ),
			array( 'Thanh toán bằng thẻ: tắt, chạy thử, thật', 'the', 'stripe card duong dan chay thu' ),
			array( 'PayPal', 'khac', 'paypal nuoc ngoai' ),
			array( 'Thời gian kích hoạt, cách liên hệ, email nhận thông báo đơn mới', 'khac', 'ho tro zalo duyet don email thong bao' ),
		) ),
		'gia'        => array( 'Giá', array(
			array( 'Bảng giá khóa học (VND, AUD, USD) và sản phẩm WooCommerce', 'bang-gia', 'gia khoa hoc san pham woocommerce tutor' ),
			array( 'Tỷ giá: kiểm tra ngay, áp dụng giá đề xuất', 'ty-gia', 'ty gia vietcombank aud usd doi gia' ),
		) ),
		'khoa-hoc'   => array( 'Khóa học', array(
			array( 'Video bài học (Bunny): ba khóa, gắn video vào bài', 'video', 'bunny video khoa api thu vien' ),
			array( 'Người dạy: email của Mây', 'nguoi-day', 'nguoi day giao vien may teacher' ),
			array( 'Tài liệu khóa học: tải PDF, file âm thanh lên', 'mp-docs', 'tai lieu pdf giao an m4a mp3' ),
		) ),
		'hoc-vien'   => array( 'Học viên', array(
			array( 'Ghi danh tay: mở khóa cho một người không cần đơn', 'ghi-danh-tay', 'ghi danh tang khoa mo khoa tai khoan dat mat khau' ),
		) ),
		'ban-le'     => array( 'Sheet và bài lẻ', array(
			array( 'Sheet nhạc: tải file PDF, xem bài nào đang bán', 'mp-sheets', 'sheet nhac lead sheet pdf' ),
			array( 'Bài hát lẻ: bài nào đang bán, bài nào còn chờ', 'mp-songs', 'bai hat le mua rieng cover solo' ),
		) ),
		'telegram'   => array( 'Telegram', array(
			array( 'Nhận yêu cầu sửa site qua Telegram: token, ghép nhóm, chạy ngay', 'telegram', 'bot telegram token nhom routine chay ngay' ),
		) ),
		'so-lieu'    => array( 'Số liệu', array(
			array( 'Số liệu cho bảng theo dõi: đếm lại ngay', 'so-lieu', 'so lieu doanh thu thong ke bang theo doi dem lai' ),
			array( 'Số người theo dõi ở trang chủ: khóa YouTube API, đọc lại ngay', 'mang-xa-hoi', 'youtube tiktok facebook fanpage nhom nguoi dang ky theo doi subscriber' ),
		) ),
	);
}

/** The tab being looked at. */
function maypiano_settings_tab() {
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	return isset( maypiano_settings_tabs()[ $tab ] ) ? $tab : 'tong-quan';
}

/** Address of one tab, and of one block in it. */
function maypiano_settings_url( $tab = '', $anchor = '', $args = array() ) {
	$args = array_merge( array( 'page' => 'maypiano' ), $tab && 'tong-quan' !== $tab ? array( 'tab' => $tab ) : array(), $args );
	return add_query_arg( $args, admin_url( 'options-general.php' ) ) . ( '' !== $anchor ? '#' . $anchor : '' );
}

/** Each tab saves its own boxes. One shared group would empty the boxes of every tab that was not on screen. */
add_action( 'admin_init', function () {
	foreach ( maypiano_fields() as $key => $field ) {
		register_setting( 'maypiano_thanh_toan', $key, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}
	register_setting( 'maypiano_thanh_toan', 'maypiano_card_mode', array( 'sanitize_callback' => 'sanitize_key' ) );
	register_setting( 'maypiano_gia', 'maypiano_prices', array( 'sanitize_callback' => 'maypiano_sanitize_prices' ) );
	register_setting( 'maypiano_gia', 'maypiano_products', array( 'sanitize_callback' => 'maypiano_sanitize_products' ) );
} );

/** The QR reader is only needed where the bank account is filled in. */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'settings_page_maypiano' !== $hook || 'thanh-toan' !== maypiano_settings_tab() ) {
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
	$tabs = maypiano_settings_tabs();
	$tab  = maypiano_settings_tab();

	echo '<div class="wrap"><h1>May Piano</h1>';
	if ( ! function_exists( 'wc_get_orders' ) ) {
		echo '<div class="notice notice-error"><p>WooCommerce đang tắt. Trang đăng ký không nhận được đơn cho tới khi bật lại.</p></div>';
	}
	if ( ! function_exists( 'tutor_utils' ) ) {
		echo '<div class="notice notice-warning"><p>Tutor LMS đang tắt. Đơn vẫn được ghi, nhưng học viên không tự được cấp quyền học.</p></div>';
	}
	echo '<nav class="nav-tab-wrapper" style="margin-bottom:16px">';
	foreach ( $tabs as $slug => $info ) {
		printf( '<a class="nav-tab%s" href="%s">%s</a>', $slug === $tab ? ' nav-tab-active' : '', esc_url( maypiano_settings_url( $slug ) ), esc_html( $info[0] ) );
	}
	echo '</nav>';

	switch ( $tab ) {
		case 'thanh-toan':
			maypiano_settings_tab_pay();
			break;
		case 'gia':
			maypiano_settings_tab_prices();
			maypiano_fx_settings_section();
			break;
		case 'khoa-hoc':
			echo '<form method="post" action="options.php">';
			settings_fields( 'maypiano_khoa_hoc' );
			maypiano_bunny_settings_section();
			maypiano_teacher_settings_section();
			submit_button();
			echo '</form>';
			maypiano_docs_settings_section();
			break;
		case 'hoc-vien':
			maypiano_enrol_by_hand_section();
			break;
		case 'ban-le':
			maypiano_sheet_settings_section();
			maypiano_song_settings_section();
			break;
		case 'telegram':
			echo '<form method="post" action="options.php">';
			settings_fields( 'maypiano_telegram' );
			maypiano_tg_settings_section();
			submit_button();
			echo '</form>';
			break;
		case 'so-lieu':
			maypiano_stats_settings_section();
			maypiano_socials_settings_section();
			break;
		default:
			maypiano_settings_tab_home();
	}
	echo '</div>';
}

/** First tab: nothing slow. A box to find a function by a few letters, and the list of everything, each line a link. */
function maypiano_settings_tab_home() {
	$tabs = maypiano_settings_tabs();
	$old  = array();
	echo '<p><input type="search" id="mp-find" class="regular-text" placeholder="Tìm chức năng, ví dụ: tỷ giá, sheet, ghi danh" autofocus></p>';
	echo '<table class="widefat striped" style="max-width:820px" id="mp-index"><thead><tr><th style="width:150px">Mục</th><th>Chức năng</th></tr></thead><tbody>';
	foreach ( $tabs as $slug => $info ) {
		foreach ( $info[1] as $item ) {
			$old[ $item[1] ] = $slug;
			printf(
				'<tr data-find="%s"><td><a href="%s">%s</a></td><td><a href="%s">%s</a></td></tr>',
				esc_attr( strtolower( remove_accents( $info[0] . ' ' . $item[0] ) ) . ' ' . $item[2] ),
				esc_url( maypiano_settings_url( $slug ) ),
				esc_html( $info[0] ),
				esc_url( maypiano_settings_url( $slug, $item[1] ) ),
				esc_html( $item[0] )
			);
		}
	}
	echo '</tbody></table><p id="mp-none" hidden><strong>Không có chức năng nào khớp.</strong></p>';
	foreach ( maypiano_curriculum_report() as $line ) {
		echo '<div class="notice notice-info inline"><p>Nội dung khóa học đã dựng từ file. ' . esc_html( $line ) . '</p></div>';
	}
	?>
<script>
(function () {
	/* Links made before the page had tabs end in #ty-gia and the like: they go on to the right tab. */
	var old = <?php echo wp_json_encode( $old ); ?>, hash = location.hash.slice(1);
	if (hash && old[hash]) { location.replace(location.pathname + '?page=maypiano&tab=' + old[hash] + '#' + hash); return; }
	var box = document.getElementById('mp-find'), rows = document.querySelectorAll('#mp-index tbody tr'), none = document.getElementById('mp-none');
	var plain = function (s) { return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd'); };
	box.addEventListener('input', function () {
		var words = plain(box.value).split(/\s+/).filter(Boolean), shown = 0;
		rows.forEach(function (row) {
			var text = row.getAttribute('data-find');
			var hit = words.every(function (w) { return text.indexOf(w) !== -1; });
			row.hidden = !hit;
			if (hit) { shown++; }
		});
		none.hidden = shown > 0;
	});
	box.addEventListener('keydown', function (e) {
		if (e.key !== 'Enter') { return; }
		for (var i = 0; i < rows.length; i++) { if (!rows[i].hidden) { location.href = rows[i].querySelector('td:last-child a').href; break; } }
	});
})();
</script>
	<?php
}

function maypiano_settings_tab_pay() {
	$fields = maypiano_fields();
	$mode   = maypiano_card_mode();
	$bank   = array( 'maypiano_bank_bin', 'maypiano_account', 'maypiano_account_name', 'maypiano_qr' );
	$other  = array( 'maypiano_paypal', 'maypiano_processing', 'maypiano_support', 'maypiano_notify' );

	echo '<form method="post" action="options.php">';
	settings_fields( 'maypiano_thanh_toan' );

	echo '<h2 id="chuyen-khoan">Chuyển khoản ngân hàng (khách ở Việt Nam)</h2>';
	echo '<p><input type="file" id="mp-qr-file" accept="image/*"> <span id="mp-qr-result" class="description">Đọc từ ảnh mã QR: chọn ảnh mã QR của ngân hàng, ba ô bên dưới sẽ tự điền. Ảnh chỉ được đọc trên máy của bạn.</span></p>';
	echo '<table class="form-table" role="presentation">';
	foreach ( $bank as $key ) {
		maypiano_settings_row( $key, $fields[ $key ] );
	}
	echo '</table>';

	echo '<h2 id="the">Thanh toán bằng thẻ</h2><table class="form-table" role="presentation"><tr><th scope="row">Chế độ</th><td>';
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

	echo '<h2 id="khac">PayPal và liên hệ</h2><table class="form-table" role="presentation">';
	foreach ( $other as $key ) {
		maypiano_settings_row( $key, $fields[ $key ] );
	}
	echo '</table>';
	submit_button();
	echo '</form>';
}

function maypiano_settings_tab_prices() {
	$prices = (array) get_option( 'maypiano_prices', array() );
	$picked = (array) get_option( 'maypiano_products', array() );

	echo '<form method="post" action="options.php">';
	settings_fields( 'maypiano_gia' );
	echo '<h2 id="bang-gia">Bảng giá và sản phẩm</h2>';
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

	submit_button();
	echo '</form>';
}
