<?php
/**
 * Follower counts on the home page. TikTok, the fanpage and the group come from data/mang-xa-hoi.json, which is
 * checked by hand every two weeks. YouTube is read once a day from the YouTube Data API (one request a day, free),
 * using the API key in Settings > May Piano > Số liệu. Without a key, or when YouTube does not answer, the number in
 * the JSON file is shown. Counts are shown rounded down to the thousand, with a "+".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_YT_CHANNEL = 'UCHTx5Zck4uICJoIzeuDdq_g';

/** The numbers as kept in the repo. */
function maypiano_socials_file() {
	$file = get_theme_file_path( 'data/mang-xa-hoi.json' );
	$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
	return is_array( $data ) ? $data : array();
}

/** What the home page shows: four counts and the day any of them last changed. */
function maypiano_socials() {
	$data = maypiano_socials_file();
	$yt   = (array) get_option( 'maypiano_yt_count', array() );
	$day  = (string) ( $data['updated'] ?? '' );
	if ( ! empty( $yt['n'] ) ) {
		$data['youtube'] = (int) $yt['n'];
		$changed         = wp_date( 'Y-m-d', (int) ( $yt['changed'] ?? 0 ) );
		if ( $changed > $day ) {
			$day = $changed;
		}
	}
	$data['updated'] = $day;
	return $data;
}

/** 135420 -> "135.000+". */
function maypiano_socials_format( $n ) {
	$n = (int) floor( (int) $n / 1000 ) * 1000;
	return $n > 0 ? number_format( $n, 0, ',', '.' ) . '+' : '';
}

/** Placeholders the home page template is filled with. */
function maypiano_socials_fill() {
	$s    = maypiano_socials();
	$date = '' !== (string) $s['updated'] ? date_create( (string) $s['updated'] ) : false;
	return array(
		'%%MP_YT%%'      => esc_html( maypiano_socials_format( $s['youtube'] ?? 0 ) ),
		'%%MP_TT%%'      => esc_html( maypiano_socials_format( $s['tiktok'] ?? 0 ) ),
		'%%MP_FP%%'      => esc_html( maypiano_socials_format( $s['fanpage'] ?? 0 ) ),
		'%%MP_GR%%'      => esc_html( maypiano_socials_format( $s['group'] ?? 0 ) ),
		'%%MP_SO_NGAY%%' => $date ? esc_html( $date->format( 'd/m/Y' ) ) : '',
	);
}

/** Asks YouTube for the subscriber count. Keeps the day the shown (rounded) number last changed. */
function maypiano_yt_refresh() {
	$key = trim( (string) get_option( 'maypiano_yt_key', '' ) );
	if ( '' === $key ) {
		update_option( 'maypiano_yt_last', array( 'time' => time(), 'error' => 'Chưa có khóa YouTube API.' ), false );
		return;
	}
	$url  = add_query_arg( array( 'part' => 'statistics', 'id' => MAYPIANO_YT_CHANNEL, 'key' => $key ), 'https://www.googleapis.com/youtube/v3/channels' );
	$res  = wp_remote_get( $url, array( 'timeout' => 15 ) );
	$body = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
	$n    = (int) ( $body['items'][0]['statistics']['subscriberCount'] ?? 0 );
	if ( $n <= 0 ) {
		$why = is_wp_error( $res ) ? $res->get_error_message() : (string) ( $body['error']['message'] ?? 'YouTube không trả về số người đăng ký.' );
		update_option( 'maypiano_yt_last', array( 'time' => time(), 'error' => $why ), false );
		return;
	}
	$old = (array) get_option( 'maypiano_yt_count', array() );
	$was = maypiano_socials_format( $old['n'] ?? ( maypiano_socials_file()['youtube'] ?? 0 ) );
	update_option( 'maypiano_yt_count', array(
		'n'       => $n,
		'changed' => maypiano_socials_format( $n ) !== $was ? time() : (int) ( $old['changed'] ?? 0 ),
	), false );
	update_option( 'maypiano_yt_last', array( 'time' => time(), 'n' => $n ), false );
}

add_action( 'maypiano_yt_daily', 'maypiano_yt_refresh' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'maypiano_yt_daily' ) ) {
		wp_schedule_event( time() + 120, 'daily', 'maypiano_yt_daily' );
	}
} );
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'maypiano_yt_daily' );
} );

add_action( 'admin_init', function () {
	register_setting( 'maypiano_so_lieu', 'maypiano_yt_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
} );
add_action( 'update_option_maypiano_yt_key', 'maypiano_yt_refresh' );
add_action( 'add_option_maypiano_yt_key', 'maypiano_yt_refresh' );

add_action( 'admin_post_maypiano_yt_refresh', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Không có quyền.' );
	}
	check_admin_referer( 'maypiano_yt_refresh' );
	maypiano_yt_refresh();
	wp_safe_redirect( maypiano_settings_url( 'so-lieu', 'mang-xa-hoi' ) );
	exit;
} );

/** Block in Settings > May Piano > Số liệu. */
function maypiano_socials_settings_section() {
	$s    = maypiano_socials();
	$last = (array) get_option( 'maypiano_yt_last', array() );
	echo '<h2 id="mang-xa-hoi">Số người theo dõi ở trang chủ</h2>';
	printf(
		'<p>Đang hiện: YouTube %s · TikTok %s · Fanpage %s · Nhóm %s · ngày %s.</p>',
		esc_html( maypiano_socials_format( $s['youtube'] ?? 0 ) ),
		esc_html( maypiano_socials_format( $s['tiktok'] ?? 0 ) ),
		esc_html( maypiano_socials_format( $s['fanpage'] ?? 0 ) ),
		esc_html( maypiano_socials_format( $s['group'] ?? 0 ) ),
		esc_html( maypiano_socials_fill()['%%MP_SO_NGAY%%'] )
	);
	echo '<p>YouTube tự đọc mỗi ngày. TikTok, fanpage và nhóm sửa trong file <code>data/mang-xa-hoi.json</code> của theme.</p>';
	if ( ! empty( $last['time'] ) ) {
		$what = ! empty( $last['n'] ) ? 'đọc được ' . number_format( (int) $last['n'], 0, ',', '.' ) . ' người đăng ký' : 'lỗi: ' . (string) ( $last['error'] ?? '' );
		printf( '<p>YouTube, lần đọc gần nhất %s: %s.</p>', esc_html( wp_date( 'd/m/Y H:i', (int) $last['time'] ) ), esc_html( $what ) );
	}
	echo '<form method="post" action="options.php"><table class="form-table" role="presentation">';
	settings_fields( 'maypiano_so_lieu' );
	printf(
		'<tr><th scope="row"><label for="maypiano_yt_key">Khóa YouTube API</label></th><td><input class="regular-text" type="password" autocomplete="off" id="maypiano_yt_key" name="maypiano_yt_key" value="%s"><p class="description">YouTube Data API v3, lấy ở Google Cloud. Lưu xong site đọc số ngay.</p></td></tr>',
		esc_attr( (string) get_option( 'maypiano_yt_key', '' ) )
	);
	echo '</table>';
	submit_button( 'Lưu khóa' );
	echo '</form>';
	printf(
		'<form method="post" action="%s">%s<input type="hidden" name="action" value="maypiano_yt_refresh"><button class="button">Đọc YouTube ngay</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'maypiano_yt_refresh', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}
