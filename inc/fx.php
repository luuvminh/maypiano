<?php
/**
 * Exchange-rate watch. Prices in AUD and USD are set by hand from the VND price.
 * Once a week the site reads Vietcombank's rates. When a rate has moved 7% or more since the prices
 * were last set, the owner gets an email with new prices to approve. Nothing changes without the owner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_FX_SOURCE    = 'https://portal.vietcombank.com.vn/Usercontrols/TVPortal.TyGia/pXML.aspx?b=10';
const MAYPIANO_FX_THRESHOLD = 0.07;

/** Rates (VND for one unit, Vietcombank buy-by-transfer) the current AUD and USD prices were worked out from. */
function maypiano_fx_base() {
	$base = (array) get_option( 'maypiano_fx_base', array() );
	return wp_parse_args( $base, array(
		'USD'  => 25790.0,
		'AUD'  => 17738.33,
		'date' => '2026-10-05',
	) );
}

/** Today's rates from Vietcombank, or a WP_Error. */
function maypiano_fx_fetch() {
	$res = wp_remote_get( MAYPIANO_FX_SOURCE, array( 'timeout' => 15 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$body  = (string) wp_remote_retrieve_body( $res );
	$rates = array();
	foreach ( array( 'USD', 'AUD' ) as $code ) {
		if ( preg_match( '/CurrencyCode="' . $code . '"[^>]*\sTransfer="([0-9.,]+)"/', $body, $m ) ) {
			$value = (float) str_replace( ',', '', $m[1] );
			if ( $value > 1000 ) {
				$rates[ $code ] = $value;
			}
		}
	}
	if ( 2 !== count( $rates ) ) {
		return new WP_Error( 'maypiano_fx', 'Không đọc được tỷ giá từ Vietcombank.' );
	}
	return $rates;
}

/** Price in a foreign currency for a VND amount: divide by the rate, round up to a whole unit. */
function maypiano_fx_convert( $vnd, $rate ) {
	return $rate > 0 ? (int) ceil( $vnd / $rate - 0.0001 ) : 0;
}

/** New AUD and USD prices for every course at the given rates. */
function maypiano_fx_prices( $rates ) {
	$out = array();
	foreach ( array_keys( maypiano_catalog() ) as $key ) {
		$vnd = (float) maypiano_price( $key, 'VND' );
		if ( $vnd <= 0 ) {
			continue;
		}
		foreach ( array( 'AUD', 'USD' ) as $code ) {
			$out[ $key ][ $code ] = maypiano_fx_convert( $vnd, $rates[ $code ] );
		}
	}
	return $out;
}

/**
 * Reads the rates and, when one has moved past the threshold, stores a proposal and emails the owner.
 * The email goes out at most once every 30 days while the gap stays.
 */
function maypiano_fx_check() {
	$rates = maypiano_fx_fetch();
	$state = array(
		'time'  => time(),
		'error' => '',
		'rates' => array(),
		'drift' => array(),
	);
	if ( is_wp_error( $rates ) ) {
		$state['error'] = $rates->get_error_message();
		update_option( 'maypiano_fx_last', $state, false );
		return $state;
	}
	$base  = maypiano_fx_base();
	$worst = 0.0;
	foreach ( $rates as $code => $rate ) {
		$state['drift'][ $code ] = ( $rate - (float) $base[ $code ] ) / (float) $base[ $code ];
		$worst                   = max( $worst, abs( $state['drift'][ $code ] ) );
	}
	$state['rates'] = $rates;
	update_option( 'maypiano_fx_last', $state, false );

	if ( $worst < MAYPIANO_FX_THRESHOLD ) {
		delete_option( 'maypiano_fx_proposal' );
		return $state;
	}
	$old      = (array) get_option( 'maypiano_fx_proposal', array() );
	$mailed   = isset( $old['mailed'] ) ? (int) $old['mailed'] : 0;
	$proposal = array(
		'rates'  => $rates,
		'drift'  => $state['drift'],
		'prices' => maypiano_fx_prices( $rates ),
		'time'   => time(),
		'mailed' => $mailed,
	);
	if ( time() - $mailed > 30 * DAY_IN_SECONDS ) {
		$proposal['mailed'] = time();
		maypiano_fx_mail( $proposal, $base );
	}
	update_option( 'maypiano_fx_proposal', $proposal, false );
	return $state;
}

function maypiano_fx_percent( $ratio ) {
	return ( $ratio >= 0 ? '+' : '−' ) . number_format( abs( $ratio ) * 100, 1, ',', '.' ) . '%';
}

/** Table of current and proposed prices, used in the email and on the settings page. */
function maypiano_fx_table( $proposal ) {
	$catalog = maypiano_catalog();
	$html    = '<table cellpadding="6" style="border-collapse:collapse"><tr><th align="left">Khóa học</th><th align="right">VND</th><th align="right">AUD hiện tại</th><th align="right">AUD đề xuất</th><th align="right">USD hiện tại</th><th align="right">USD đề xuất</th></tr>';
	foreach ( $proposal['prices'] as $key => $new ) {
		$html .= sprintf(
			'<tr><td>%s</td><td align="right">%s</td><td align="right">%s</td><td align="right"><strong>%s</strong></td><td align="right">%s</td><td align="right"><strong>%s</strong></td></tr>',
			esc_html( $catalog[ $key ]['name'] ),
			esc_html( number_format( (float) maypiano_price( $key, 'VND' ), 0, ',', '.' ) ),
			esc_html( (string) ( maypiano_price( $key, 'AUD' ) ?? '—' ) ),
			esc_html( (string) $new['AUD'] ),
			esc_html( (string) ( maypiano_price( $key, 'USD' ) ?? '—' ) ),
			esc_html( (string) $new['USD'] )
		);
	}
	return $html . '</table>';
}

function maypiano_fx_mail( $proposal, $base ) {
	$lines = '';
	foreach ( $proposal['rates'] as $code => $rate ) {
		$lines .= sprintf(
			'<li>1 %s: %s ₫ lúc đặt giá (%s), nay %s ₫ (%s)</li>',
			esc_html( $code ),
			esc_html( number_format( (float) $base[ $code ], 0, ',', '.' ) ),
			esc_html( $base['date'] ),
			esc_html( number_format( $rate, 0, ',', '.' ) ),
			esc_html( maypiano_fx_percent( $proposal['drift'][ $code ] ) )
		);
	}
	$body  = '<p>Tỷ giá đã lệch từ 7% trở lên so với lúc đặt giá AUD và USD. Bạn có muốn đổi giá không?</p>';
	$body .= '<ul>' . $lines . '</ul>';
	$body .= '<p>Giá đề xuất = giá VND chia cho tỷ giá Vietcombank mua chuyển khoản hôm nay, làm tròn lên.</p>';
	$body .= maypiano_fx_table( $proposal );
	$body .= '<p>Muốn đổi thì bấm nút dưới, rồi bấm "Áp dụng giá đề xuất" trong trang cài đặt. Không muốn đổi thì không cần làm gì: giá giữ nguyên.</p>';
	$body .= maypiano_mail_button( admin_url( 'options-general.php?page=maypiano#ty-gia' ), 'Mở trang cài đặt' );
	maypiano_mail( maypiano_notify_address(), 'May Piano: tỷ giá lệch ' . maypiano_fx_percent( max( array_map( 'abs', $proposal['drift'] ) ) ) . ', có đổi giá không?', $body );
}

/**
 * Copies AUD and USD prices onto the WooCommerce products, for the Price Based on Country zones
 * "uc" (AUD) and "quoc-te" (USD), so the course page shows the same price as the sign-up page.
 */
function maypiano_fx_sync_products( $prices, $rates ) {
	$zones = array(
		'AUD' => 'uc',
		'USD' => 'quoc-te',
	);
	foreach ( $prices as $key => $new ) {
		$product = maypiano_product_for( $key );
		if ( ! $product ) {
			continue;
		}
		$regular_vnd = (float) $product->get_regular_price( 'edit' );
		foreach ( $zones as $code => $zone ) {
			if ( '' === (string) get_post_meta( $product->get_id(), "_{$zone}_price_method", true ) ) {
				continue;
			}
			$price   = (int) $new[ $code ];
			$regular = max( $price, maypiano_fx_convert( $regular_vnd, $rates[ $code ] ) );
			update_post_meta( $product->get_id(), "_{$zone}_regular_price", (string) $regular );
			update_post_meta( $product->get_id(), "_{$zone}_sale_price", $regular > $price ? (string) $price : '' );
			update_post_meta( $product->get_id(), "_{$zone}_price", (string) $price );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product->get_id() );
		}
	}
}

add_action( 'maypiano_fx_weekly', 'maypiano_fx_check' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'maypiano_fx_weekly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'maypiano_fx_weekly' );
	}
} );
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'maypiano_fx_weekly' );
} );

/** "Check now" button on the settings page. */
add_action( 'admin_post_maypiano_fx_check', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Không có quyền.' );
	}
	check_admin_referer( 'maypiano_fx_check' );
	maypiano_fx_check();
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#ty-gia' ) );
	exit;
} );

/** "Apply the proposed prices" button on the settings page. */
add_action( 'admin_post_maypiano_fx_apply', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Không có quyền.' );
	}
	check_admin_referer( 'maypiano_fx_apply' );
	$proposal = (array) get_option( 'maypiano_fx_proposal', array() );
	if ( ! empty( $proposal['prices'] ) && ! empty( $proposal['rates'] ) ) {
		$table = (array) get_option( 'maypiano_prices', array() );
		foreach ( $proposal['prices'] as $key => $new ) {
			$table[ $key ]['AUD'] = (float) $new['AUD'];
			$table[ $key ]['USD'] = (float) $new['USD'];
		}
		update_option( 'maypiano_prices', $table );
		update_option( 'maypiano_fx_base', array(
			'USD'  => (float) $proposal['rates']['USD'],
			'AUD'  => (float) $proposal['rates']['AUD'],
			'date' => wp_date( 'Y-m-d' ),
		), false );
		maypiano_fx_sync_products( $proposal['prices'], $proposal['rates'] );
		delete_option( 'maypiano_fx_proposal' );
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#ty-gia' ) );
	exit;
} );

/** The exchange-rate block on the settings page. */
function maypiano_fx_settings_section() {
	$base     = maypiano_fx_base();
	$last     = (array) get_option( 'maypiano_fx_last', array() );
	$proposal = (array) get_option( 'maypiano_fx_proposal', array() );
	$next     = wp_next_scheduled( 'maypiano_fx_weekly' );
	echo '<h2 id="ty-gia">Tỷ giá</h2>';
	echo '<p>Giá AUD và USD được tính từ giá VND. Mỗi tuần site đọc tỷ giá Vietcombank (mua chuyển khoản) một lần. Khi tỷ giá lệch từ 7% trở lên so với lúc đặt giá, bạn nhận một email hỏi có đổi giá không. Giá chỉ đổi khi bạn bấm áp dụng.</p>';
	printf(
		'<p>Tỷ giá lúc đặt giá (%s): 1 USD = %s ₫, 1 AUD = %s ₫.</p>',
		esc_html( $base['date'] ),
		esc_html( number_format( (float) $base['USD'], 0, ',', '.' ) ),
		esc_html( number_format( (float) $base['AUD'], 0, ',', '.' ) )
	);
	if ( ! empty( $last['time'] ) ) {
		if ( ! empty( $last['error'] ) ) {
			printf( '<p>Lần kiểm tra gần nhất (%s) bị lỗi: %s Giá giữ nguyên.</p>', esc_html( wp_date( 'd/m/Y H:i', (int) $last['time'] ) ), esc_html( $last['error'] ) );
		} else {
			printf(
				'<p>Lần kiểm tra gần nhất (%s): 1 USD = %s ₫ (%s), 1 AUD = %s ₫ (%s).</p>',
				esc_html( wp_date( 'd/m/Y H:i', (int) $last['time'] ) ),
				esc_html( number_format( (float) $last['rates']['USD'], 0, ',', '.' ) ),
				esc_html( maypiano_fx_percent( (float) $last['drift']['USD'] ) ),
				esc_html( number_format( (float) $last['rates']['AUD'], 0, ',', '.' ) ),
				esc_html( maypiano_fx_percent( (float) $last['drift']['AUD'] ) )
			);
		}
	} else {
		echo '<p>Chưa kiểm tra lần nào.</p>';
	}
	if ( $next ) {
		printf( '<p>Lần kiểm tra tự động kế tiếp: %s.</p>', esc_html( wp_date( 'd/m/Y H:i', $next ) ) );
	}
	if ( ! empty( $proposal['prices'] ) ) {
		echo '<h3>Giá đề xuất theo tỷ giá mới</h3>';
		echo wp_kses_post( maypiano_fx_table( $proposal ) );
		printf(
			'<form method="post" action="%s" style="margin-top:12px">%s<input type="hidden" name="action" value="maypiano_fx_apply"><button class="button button-primary">Áp dụng giá đề xuất</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'maypiano_fx_apply', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}
	printf(
		'<form method="post" action="%s" style="margin-top:12px">%s<input type="hidden" name="action" value="maypiano_fx_check"><button class="button">Kiểm tra tỷ giá ngay</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'maypiano_fx_check', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}
