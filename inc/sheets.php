<?php
/**
 * Sheet nhạc: the page at /sheet-nhac/, where single sheets are sold.
 *
 * The list of sheets is data/sheet-nhac.json. The PDF files are NOT in the theme (the repo is public):
 * the owner uploads them at Cài đặt > May Piano > Sheet nhạc, and they are kept under a name nobody can guess.
 * A sheet is on sale only once its file is there. Buyers pay through the same order flow as courses and
 * download through a link tied to their paid order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Makes the page once, so the address works as soon as the theme is deployed. */
add_action( 'init', function () {
	if ( get_option( 'maypiano_sheet_page' ) ) {
		return;
	}
	if ( ! get_page_by_path( 'sheet-nhac' ) ) {
		$id = wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Sheet nhạc',
			'post_name'   => 'sheet-nhac',
		) );
		if ( ! $id || is_wp_error( $id ) ) {
			return;
		}
	}
	update_option( 'maypiano_sheet_page', 1 );
}, 20 );

/** The two kinds of sheet and what each costs. VND and USD follow the site's exchange rate of 05/10/2026. */
function maypiano_sheet_kinds() {
	return array(
		'LS' => array(
			'label' => 'Lead Sheet',
			'about' => 'Khóa Sol, hợp âm và gạch nhịp',
			'VND'   => 105000,
			'AUD'   => 6,
			'USD'   => 5,
		),
		'NC' => array(
			'label' => 'Sheet Nâng Cao',
			'about' => 'Khóa Sol và khóa Fa',
			'VND'   => 175000,
			'AUD'   => 10,
			'USD'   => 7,
		),
	);
}

/**
 * Every sheet in the file, keyed by slug. Each one: slug, title, kind, pages, note.
 * A sheet without a title or with an unknown kind is left out.
 */
function maypiano_sheets() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out  = array();
	$file = get_theme_file_path( 'data/sheet-nhac.json' );
	if ( ! is_readable( $file ) ) {
		return $out;
	}
	$data  = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$kinds = maypiano_sheet_kinds();
	foreach ( (array) ( $data['sheets'] ?? array() ) as $row ) {
		$title = trim( (string) ( $row['title'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $row['slug'] ?? '' ) );
		$kind  = strtoupper( (string) ( $row['kind'] ?? 'LS' ) );
		if ( '' === $title || '' === $slug || ! isset( $kinds[ $kind ] ) ) {
			continue;
		}
		$out[ $slug ] = array(
			'slug'  => $slug,
			'title' => $title,
			'kind'  => $kind,
			'pages' => absint( $row['pages'] ?? 0 ),
			'note'  => trim( (string) ( $row['note'] ?? '' ) ),
		);
	}
	return $out;
}

function maypiano_sheet_price( $slug, $currency ) {
	$sheets = maypiano_sheets();
	$kinds  = maypiano_sheet_kinds();
	if ( ! isset( $sheets[ $slug ] ) || ! isset( $kinds[ $sheets[ $slug ]['kind'] ][ $currency ] ) ) {
		return null;
	}
	return (float) $kinds[ $sheets[ $slug ]['kind'] ][ $currency ];
}

/** Where the uploaded PDFs live. */
function maypiano_sheet_dir() {
	$up = wp_upload_dir();
	return trailingslashit( $up['basedir'] ) . 'mp-sheets';
}

/** Full path of a sheet's PDF, or '' when it has not been uploaded. */
function maypiano_sheet_file( $slug ) {
	$files = (array) get_option( 'maypiano_sheet_files', array() );
	if ( empty( $files[ $slug ] ) ) {
		return '';
	}
	$path = trailingslashit( maypiano_sheet_dir() ) . basename( (string) $files[ $slug ] );
	return is_readable( $path ) ? $path : '';
}

/** Sheets that can be bought right now: listed in the file and with a PDF uploaded. */
function maypiano_sheets_on_sale() {
	return array_filter( maypiano_sheets(), function ( $sheet ) {
		return '' !== maypiano_sheet_file( $sheet['slug'] );
	} );
}

function maypiano_is_sheet_order( $order ) {
	return $order instanceof WC_Order && 'sheet' === $order->get_meta( '_mp_kind' );
}

/** The link a buyer downloads one sheet with. Works only while the order is paid. */
function maypiano_sheet_download_url( $order, $slug ) {
	return add_query_arg( array(
		'mp_tai' => $slug,
		'don'    => $order->get_id(),
		'key'    => $order->get_order_key(),
	), home_url( '/' ) );
}

/** Name and download link of every sheet on an order. */
function maypiano_sheet_downloads( $order ) {
	$sheets = maypiano_sheets();
	$out    = array();
	foreach ( $order->get_items() as $item ) {
		$slug = (string) $item->get_meta( '_mp_sheet' );
		if ( '' !== $slug && isset( $sheets[ $slug ] ) ) {
			$out[] = array(
				'name' => $item->get_name(),
				'url'  => maypiano_sheet_download_url( $order, $slug ),
			);
		}
	}
	return $out;
}

/**
 * Places an order for one sheet. The price is worked out here, never taken from the browser.
 *
 * @return WC_Order|WP_Error
 */
function maypiano_place_sheet_order( $args ) {
	$on_sale = maypiano_sheets_on_sale();
	$slug    = (string) $args['sheet'];
	if ( ! isset( $on_sale[ $slug ] ) ) {
		return new WP_Error( 'empty', 'This sheet is not on sale', array( 'status' => 400 ) );
	}
	$sheet    = $on_sale[ $slug ];
	$kinds    = maypiano_sheet_kinds();
	$region   = maypiano_region( $args['region'] );
	$currency = maypiano_currency_for( $region );
	$method   = 'VN' === $region ? 'bank' : 'paypal';
	$price    = maypiano_sheet_price( $slug, $currency );
	if ( null === $price ) {
		return new WP_Error( 'price', 'No price', array( 'status' => 400 ) );
	}

	$order = wc_create_order( array(
		'status'      => 'pending',
		'created_via' => 'maypiano',
	) );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$order->set_currency( $currency );
	$order->update_meta_data( '_mp_flow', 1 );
	$order->update_meta_data( '_mp_kind', 'sheet' );
	$order->update_meta_data( '_mp_token', $args['token'] );
	$order->update_meta_data( '_mp_region', $region );
	$order->update_meta_data( '_mp_method', $method );

	$item = new WC_Order_Item_Product();
	$item->set_name( 'Sheet nhạc: ' . $sheet['title'] . ' (' . $kinds[ $sheet['kind'] ]['label'] . ')' );
	$item->set_quantity( 1 );
	$item->set_subtotal( $price );
	$item->set_total( $price );
	$item->add_meta_data( '_mp_sheet', $slug, true );
	$order->add_item( $item );

	$order->set_billing_first_name( $args['name'] );
	$order->set_billing_email( $args['email'] );
	$order->set_billing_country( 'INTL' === $region ? '' : $region );
	if ( class_exists( 'WC_Geolocation' ) ) {
		$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
	}
	$order->set_payment_method( 'bank' === $method ? 'bacs' : 'mp_paypal' );
	$order->set_payment_method_title( 'bank' === $method ? 'Chuyển khoản ngân hàng' : 'PayPal' );
	$order->calculate_totals( false );
	$order->save();
	$order->update_status( 'on-hold', 'Chờ khách trả tiền.' );

	maypiano_mail_order_received( $order );
	maypiano_mail_owner_new_order( $order );
	return $order;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'maypiano/v1', '/sheet-order', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			nocache_headers();
			if ( ! function_exists( 'wc_create_order' ) ) {
				return new WP_Error( 'unavailable', 'Shop is off', array( 'status' => 503 ) );
			}
			if ( '' !== (string) $req->get_param( 'website' ) ) {
				return new WP_Error( 'invalid', 'Invalid request', array( 'status' => 400 ) );
			}
			$token = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $req->get_param( 'token' ) );
			if ( strlen( $token ) >= 16 ) {
				$again = maypiano_order_by_token( $token );
				if ( $again ) {
					return array( 'url' => maypiano_order_url( $again ) );
				}
			} else {
				$token = wp_generate_uuid4();
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$name  = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			if ( ! is_email( $email ) || '' === $name || mb_strlen( $name ) > 80 ) {
				return new WP_Error( 'invalid', 'Missing name or email', array( 'status' => 400 ) );
			}
			if ( maypiano_throttled( 'order', 30 ) ) {
				return new WP_Error( 'too_many', 'Too many requests', array( 'status' => 429 ) );
			}
			$order = maypiano_place_sheet_order( array(
				'sheet'  => sanitize_title( (string) $req->get_param( 'sheet' ) ),
				'name'   => $name,
				'email'  => $email,
				'region' => (string) $req->get_param( 'region' ),
				'token'  => $token,
			) );
			return is_wp_error( $order ) ? $order : array( 'url' => maypiano_order_url( $order ) );
		},
	) );
} );

/** Payment confirmed on a sheet order: nothing to open, the buyer just gets the download links. */
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to ) {
	$order = wc_get_order( $order_id );
	if ( 'completed' !== $to || ! maypiano_is_sheet_order( $order ) || $order->get_meta( '_mp_fulfilled' ) ) {
		return;
	}
	$order->update_meta_data( '_mp_fulfilled', time() );
	$order->update_meta_data( '_mp_access', 'open' );
	$order->save();
	$order->add_order_note( 'Đã gửi link tải sheet cho khách.' );
	maypiano_mail_sheet_ready( $order );
}, 20, 3 );

/** To the customer, once payment is confirmed: the download buttons. */
function maypiano_mail_sheet_ready( $order ) {
	$code = maypiano_order_code( $order );
	$body = '<p>Chào ' . esc_html( $order->get_billing_first_name() ) . ',</p><p>Mây đã nhận được tiền cho đơn <strong>' . esc_html( $code ) . '</strong>. Cảm ơn bạn nhiều.</p>' . maypiano_mail_lines( $order )
		. '<p>Sheet của bạn đây. Bạn bấm nút để tải file PDF về máy:</p>';
	foreach ( maypiano_sheet_downloads( $order ) as $file ) {
		$body .= maypiano_mail_button( $file['url'], 'Tải ' . preg_replace( '/^Sheet nhạc: /', '', $file['name'] ) );
	}
	$body .= '<p style="font-size:15px">Bạn giữ email này để tải lại khi cần. Sheet dành cho riêng bạn tập, bạn đừng chia sẻ file cho người khác nhé.</p>' . maypiano_mail_support();
	return maypiano_mail( $order->get_billing_email(), 'Sheet nhạc của bạn đây, đơn ' . $code, $body );
}

/** Hands the PDF to someone holding the key of a paid order that contains this sheet. */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['mp_tai'] ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	$slug  = sanitize_title( wp_unslash( $_GET['mp_tai'] ) );
	$order = function_exists( 'wc_get_order' ) && isset( $_GET['don'] ) ? wc_get_order( absint( $_GET['don'] ) ) : null;
	$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$owns  = false;
	if ( maypiano_is_sheet_order( $order ) && '' !== $key && hash_equals( $order->get_order_key(), $key ) && 'paid' === maypiano_order_state( $order ) ) {
		foreach ( $order->get_items() as $item ) {
			if ( $slug === (string) $item->get_meta( '_mp_sheet' ) ) {
				$owns = true;
			}
		}
	}
	$path = $owns ? maypiano_sheet_file( $slug ) : '';
	if ( '' === $path || maypiano_throttled( 'tai', 60 ) ) {
		wp_die( 'Đường dẫn tải này không dùng được. Bạn mở lại từ email của Mây, hoặc trả lời email đó để Mây giúp nhé.', 'Không tải được sheet', array( 'response' => 404 ) );
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="MayPiano-' . $slug . '.pdf"' );
	header( 'Content-Length: ' . filesize( $path ) );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );

/**
 * Cài đặt > May Piano > Sheet nhạc: the owner picks the PDFs (several at once). A file named
 * MayPiano_<slug>_<KIND>.pdf, or just <slug>.pdf, goes to the sheet with that slug.
 */
add_action( 'admin_post_maypiano_sheet_upload', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed' );
	}
	check_admin_referer( 'maypiano_sheet_upload' );
	$sheets = maypiano_sheets();
	$files  = (array) get_option( 'maypiano_sheet_files', array() );
	$dir    = maypiano_sheet_dir();
	wp_mkdir_p( $dir );
	if ( ! file_exists( $dir . '/index.html' ) ) {
		file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$done = array();
	$skip = array();
	$up   = isset( $_FILES['mp_sheets'] ) ? $_FILES['mp_sheets'] : array( 'name' => array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( (array) $up['name'] as $i => $name ) {
		$name = sanitize_file_name( (string) $name );
		$slug = sanitize_title( preg_replace( '/^maypiano_|_(ls|nc)(_[a-z0-9#]+)?$/i', '', preg_replace( '/\.pdf$/i', '', $name ) ) );
		$tmp  = (string) $up['tmp_name'][ $i ];
		$head = is_uploaded_file( $tmp ) ? (string) file_get_contents( $tmp, false, null, 0, 5 ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! isset( $sheets[ $slug ] ) || UPLOAD_ERR_OK !== (int) $up['error'][ $i ] || '%PDF-' !== $head ) {
			$skip[] = $name;
			continue;
		}
		$stored = $slug . '-' . strtolower( wp_generate_password( 28, false ) ) . '.pdf';
		if ( ! move_uploaded_file( $tmp, $dir . '/' . $stored ) ) {
			$skip[] = $name;
			continue;
		}
		if ( ! empty( $files[ $slug ] ) && is_file( $dir . '/' . basename( $files[ $slug ] ) ) ) {
			unlink( $dir . '/' . basename( $files[ $slug ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$files[ $slug ] = $stored;
		$done[]         = $sheets[ $slug ]['title'];
	}
	update_option( 'maypiano_sheet_files', $files, false );
	set_transient( 'maypiano_sheet_upload_note', array( $done, $skip ), 60 );
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#mp-sheets' ) );
	exit;
} );

function maypiano_sheet_settings_section() {
	$sheets = maypiano_sheets();
	$kinds  = maypiano_sheet_kinds();
	echo '<h2 id="mp-sheets">Sheet nhạc</h2>';
	$note = get_transient( 'maypiano_sheet_upload_note' );
	if ( is_array( $note ) ) {
		delete_transient( 'maypiano_sheet_upload_note' );
		if ( $note[0] ) {
			echo '<div class="notice notice-success inline"><p>Đã nhận file cho: ' . esc_html( implode( ', ', $note[0] ) ) . '.</p></div>';
		}
		if ( $note[1] ) {
			echo '<div class="notice notice-warning inline"><p>Bỏ qua (không phải PDF, hoặc tên file không khớp bài nào): ' . esc_html( implode( ', ', $note[1] ) ) . '.</p></div>';
		}
	}
	echo '<p>Danh sách bài nằm trong <code>data/sheet-nhac.json</code>. Bài nào có file PDF thì trang Sheet nhạc mới hiện nút mua. Chọn nhiều file một lần được; tên file dạng <code>MayPiano_&lt;slug&gt;_LS.pdf</code> tự vào đúng bài.</p>';
	echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Bài</th><th>Loại</th><th>Slug</th><th>File PDF</th></tr></thead><tbody>';
	foreach ( $sheets as $sheet ) {
		$has = '' !== maypiano_sheet_file( $sheet['slug'] );
		echo '<tr><td>' . esc_html( $sheet['title'] ) . '</td><td>' . esc_html( $kinds[ $sheet['kind'] ]['label'] ) . '</td><td><code>' . esc_html( $sheet['slug'] ) . '</code></td><td>' . ( $has ? 'Đã có, đang bán' : '<strong>Chưa có</strong>' ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
	wp_nonce_field( 'maypiano_sheet_upload' );
	echo '<input type="hidden" name="action" value="maypiano_sheet_upload"><input type="file" name="mp_sheets[]" accept="application/pdf" multiple required> ';
	submit_button( 'Tải file PDF lên', 'secondary', 'submit', false );
	echo '</form>';
}

add_action( 'wp_head', function () {
	if ( is_page( 'sheet-nhac' ) ) {
		echo '<meta name="description" content="' . esc_attr( 'Sheet nhạc piano Mây viết tay cho học trò, có hợp âm và gạch nhịp. Mua từng bài, nhận file PDF qua email.' ) . '">' . "\n";
	}
}, 2 );
