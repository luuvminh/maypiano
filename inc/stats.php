<?php
/**
 * Numbers for the owner's dashboard: orders, new learners, course sign-ups, where visitors come from, and video
 * plays on Bunny. Once an hour the site counts them per day and keeps each set as one private record of the type
 * "mp_so_lieu" (rows as JSON in its "mp_rows" field). Only someone who can edit pages can read these records, so
 * the dashboard reads them with the owner's own WordPress.com access. Nothing here is shown to visitors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_STATS_TYPE      = 'mp_so_lieu';
const MAYPIANO_STATS_DAYS      = 190;
const MAYPIANO_STATS_VIDEO_RUN = 30;

add_action( 'init', function () {
	register_post_type( MAYPIANO_STATS_TYPE, array(
		'label'               => 'Số liệu',
		'public'              => false,
		'show_ui'             => false,
		'show_in_rest'        => true,
		'rest_base'           => MAYPIANO_STATS_TYPE,
		'exclude_from_search' => true,
		'supports'            => array( 'title', 'custom-fields' ),
		'capability_type'     => 'page',
		'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'        => true,
	) );
	foreach ( array( 'mp_rows', 'mp_updated' ) as $key ) {
		register_post_meta( MAYPIANO_STATS_TYPE, $key, array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_pages' );
			},
		) );
	}
} );

/** Day in the site's time zone, as Y-m-d. */
function maypiano_stats_day( $time ) {
	return wp_date( 'Y-m-d', (int) $time );
}

/** First day counted. */
function maypiano_stats_since() {
	return wp_date( 'Y-m-d', time() - ( MAYPIANO_STATS_DAYS - 1 ) * DAY_IN_SECONDS );
}

/** Keeps one set of rows in its record, creating the record the first time. */
function maypiano_stats_save( $slug, $title, $rows ) {
	$found = get_posts( array(
		'post_type'      => MAYPIANO_STATS_TYPE,
		'name'           => $slug,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	$id = $found ? (int) $found[0] : 0;
	if ( ! $id ) {
		$id = (int) wp_insert_post( array(
			'post_type'   => MAYPIANO_STATS_TYPE,
			'post_status' => 'private',
			'post_title'  => $title,
			'post_name'   => $slug,
		) );
	}
	if ( ! $id ) {
		return;
	}
	update_post_meta( $id, 'mp_rows', wp_slash( wp_json_encode( array_values( $rows ), JSON_UNESCAPED_UNICODE ) ) );
	update_post_meta( $id, 'mp_updated', gmdate( 'c' ) );
}

/** An order's amount in đồng: foreign currencies at the rates the current prices were set from. */
function maypiano_stats_vnd( $amount, $currency ) {
	if ( 'VND' === $currency ) {
		return (float) $amount;
	}
	$base = maypiano_fx_base();
	return isset( $base[ $currency ] ) ? (float) $amount * (float) $base[ $currency ] : 0.0;
}

/** Paid orders per day, by kind: Khóa học, Bài hát lẻ, Sheet nhạc. Test orders and refunds are left out. */
function maypiano_stats_orders() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$orders = wc_get_orders( array(
		'status'       => array( 'completed' ),
		'date_created' => '>=' . strtotime( maypiano_stats_since() . ' 00:00:00 ' . wp_timezone_string() ),
		'limit'        => -1,
	) );
	$out = array();
	foreach ( $orders as $order ) {
		if ( $order->get_meta( '_mp_test' ) ) {
			continue;
		}
		$paid = $order->get_date_paid() ?: $order->get_date_completed() ?: $order->get_date_created();
		$day  = maypiano_stats_day( $paid->getTimestamp() );
		$cur  = $order->get_currency();
		$sum  = array();
		foreach ( $order->get_items() as $item ) {
			$key  = (string) $item->get_meta( '_mp_key' );
			$kind = 'sheet' === $order->get_meta( '_mp_kind' ) ? 'Sheet nhạc' : ( 0 === strpos( $key, 'bai-' ) ? 'Bài hát lẻ' : 'Khóa học' );
			$sum[ $kind ] = ( $sum[ $kind ] ?? 0 ) + maypiano_stats_vnd( (float) $item->get_total(), $cur );
		}
		if ( ! $sum ) {
			$sum['Khóa học'] = maypiano_stats_vnd( (float) $order->get_total(), $cur );
		}
		foreach ( $sum as $kind => $vnd ) {
			$k = $day . '|' . $kind;
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'day' => $day, 'kind' => $kind, 'orders' => 0, 'revenue_vnd' => 0 );
			}
			$out[ $k ]['orders']++;
			$out[ $k ]['revenue_vnd'] += (int) round( $vnd );
		}
	}
	ksort( $out );
	return $out;
}

/** Course sign-ups per day, by course. Songs bought on their own are left out: they are not courses. */
function maypiano_stats_enrolments() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DATE(e.post_date) AS day, e.post_parent AS course, COUNT(*) AS n
		FROM {$wpdb->posts} e
		LEFT JOIN {$wpdb->postmeta} s ON s.post_id = e.post_parent AND s.meta_key = '_maypiano_song'
		WHERE e.post_type = 'tutor_enrolled' AND e.post_status = 'completed' AND e.post_date >= %s AND s.meta_id IS NULL
		GROUP BY day, course ORDER BY day",
		maypiano_stats_since() . ' 00:00:00'
	) );
	$out = array();
	foreach ( (array) $rows as $row ) {
		$out[] = array( 'day' => $row->day, 'course' => html_entity_decode( get_the_title( (int) $row->course ) ), 'enrolments' => (int) $row->n );
	}
	return $out;
}

/**
 * New learners per day: the day of each learner's first sign-up to a course or a song. Learners moved over from the
 * old site keep the dates of their sign-ups there, so the day of the move does not count them all as new.
 */
function maypiano_stats_signups() {
	global $wpdb;
	$since = maypiano_stats_since();
	$days  = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DATE(MIN(post_date)) AS first FROM {$wpdb->posts}
		WHERE post_type = 'tutor_enrolled' AND post_status = 'completed'
		GROUP BY post_author HAVING first >= %s",
		$since
	) );
	$count = array_count_values( (array) $days );
	$out   = array();
	for ( $t = strtotime( $since . ' 12:00:00 ' . wp_timezone_string() ); $t <= time(); $t += DAY_IN_SECONDS ) {
		$day   = maypiano_stats_day( $t );
		$out[] = array( 'day' => $day, 'students' => $count[ $day ] ?? 0 );
	}
	return $out;
}

/** One answer of the site's stats (Jetpack), as an array, or null. */
function maypiano_stats_jetpack( $method, $args ) {
	if ( ! class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
		return null;
	}
	$stats = new \Automattic\Jetpack\Stats\WPCOM_Stats();
	if ( ! method_exists( $stats, $method ) ) {
		return null;
	}
	$res = $stats->$method( $args );
	if ( is_wp_error( $res ) || ! $res ) {
		return null;
	}
	return json_decode( wp_json_encode( $res ), true );
}

/** Which of a handful of sources a referrer group belongs to. */
function maypiano_stats_source( $name ) {
	$name = strtolower( (string) $name );
	foreach ( array(
		'YouTube'  => array( 'youtube', 'youtu.be' ),
		'Facebook' => array( 'facebook', 'fb.', 'messenger', 'instagram' ),
		'Tìm kiếm' => array( 'search', 'google', 'bing', 'coccoc', 'yahoo', 'duckduckgo' ),
		'Zalo'     => array( 'zalo' ),
	) as $source => $needles ) {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				return $source;
			}
		}
	}
	return 'Trang khác';
}

/**
 * Page views per day by where the visitor came from. Views with no referrer count as "Gõ thẳng địa chỉ".
 * Null when the site's stats cannot be read.
 */
function maypiano_stats_sources() {
	$days  = MAYPIANO_STATS_DAYS;
	$refs  = maypiano_stats_jetpack( 'get_referrers', array( 'period' => 'day', 'date' => wp_date( 'Y-m-d' ), 'num' => $days, 'max' => 100 ) );
	$visit = maypiano_stats_jetpack( 'get_visits', array( 'unit' => 'day', 'quantity' => $days, 'date' => wp_date( 'Y-m-d' ) ) );
	if ( ! $refs || ! $visit || empty( $visit['data'] ) ) {
		return null;
	}
	$fields = array_flip( (array) ( $visit['fields'] ?? array( 'period', 'views', 'visitors' ) ) );
	$out    = array();
	foreach ( (array) $visit['data'] as $row ) {
		$day   = substr( (string) $row[ $fields['period'] ?? 0 ], 0, 10 );
		$views = (int) ( $row[ $fields['views'] ?? 1 ] ?? 0 );
		$by    = array();
		foreach ( (array) ( $refs['days'][ $day ]['groups'] ?? array() ) as $group ) {
			$source        = maypiano_stats_source( ( $group['group'] ?? '' ) . ' ' . ( $group['name'] ?? '' ) );
			$by[ $source ] = ( $by[ $source ] ?? 0 ) + (int) ( $group['total'] ?? 0 );
		}
		$by['Gõ thẳng địa chỉ'] = max( 0, $views - array_sum( $by ) );
		foreach ( $by as $source => $n ) {
			if ( $n > 0 ) {
				$out[] = array( 'day' => $day, 'source' => $source, 'views' => $n );
			}
		}
	}
	return $out;
}

/** Page views per day by country: Việt Nam, Úc, Mỹ, everything else together. Null when stats cannot be read. */
function maypiano_stats_countries() {
	$res = maypiano_stats_jetpack( 'get_views_by_country', array( 'period' => 'day', 'date' => wp_date( 'Y-m-d' ), 'num' => MAYPIANO_STATS_DAYS, 'max' => 50 ) );
	if ( ! $res || ! isset( $res['days'] ) ) {
		return null;
	}
	$names = array( 'VN' => 'Việt Nam', 'AU' => 'Úc', 'US' => 'Mỹ' );
	$out   = array();
	foreach ( (array) $res['days'] as $day => $info ) {
		$by = array();
		foreach ( (array) ( $info['views'] ?? array() ) as $row ) {
			$name        = $names[ strtoupper( (string) ( $row['country_code'] ?? '' ) ) ] ?? 'Nước khác';
			$by[ $name ] = ( $by[ $name ] ?? 0 ) + (int) ( $row['views'] ?? 0 );
		}
		if ( ! empty( $info['other_views'] ) ) {
			$by['Nước khác'] = ( $by['Nước khác'] ?? 0 ) + (int) $info['other_views'];
		}
		foreach ( $by as $country => $n ) {
			if ( $n > 0 ) {
				$out[] = array( 'day' => substr( (string) $day, 0, 10 ), 'country' => $country, 'views' => $n );
			}
		}
	}
	usort( $out, function ( $a, $b ) {
		return strcmp( $a['day'], $b['day'] );
	} );
	return $out;
}

/** Bunny's daily plays and watch time, for the whole library or one video. Null when Bunny cannot be read. */
function maypiano_stats_bunny_chart( $from, $guid = '' ) {
	$library = rawurlencode( trim( (string) get_option( 'maypiano_bunny_library' ) ) );
	$url     = 'https://video.bunnycdn.com/library/' . $library . '/statistics?hourly=false&dateFrom=' . rawurlencode( $from . 'T00:00:00Z' ) . '&dateTo=' . rawurlencode( gmdate( 'Y-m-d' ) . 'T23:59:59Z' );
	if ( '' !== $guid ) {
		$url .= '&videoGuid=' . rawurlencode( $guid );
	}
	$res = wp_remote_get( $url, array(
		'timeout' => 20,
		'headers' => array(
			'AccessKey' => trim( (string) get_option( 'maypiano_bunny_api' ) ),
			'accept'    => 'application/json',
		),
	) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return null;
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! is_array( $body ) ) {
		return null;
	}
	$out = array();
	foreach ( (array) ( $body['viewsChart'] ?? array() ) as $when => $n ) {
		$out[ substr( (string) $when, 0, 10 ) ]['plays'] = (int) $n;
	}
	foreach ( (array) ( $body['watchTimeChart'] ?? array() ) as $when => $n ) {
		$out[ substr( (string) $when, 0, 10 ) ]['seconds'] = (int) $n;
	}
	ksort( $out );
	return $out;
}

/** What the last call to Bunny's account API answered, in a few words, for the dashboard's notes. */
function maypiano_stats_bunny_note( $add = null ) {
	static $notes = array();
	if ( null !== $add ) {
		$notes[] = $add;
	}
	return implode( ' · ', $notes );
}

/** One answer of Bunny's account API (needs the account key), as an array, or null. */
function maypiano_stats_bunny_account( $path ) {
	$key = trim( (string) get_option( 'maypiano_bunny_account', '' ) );
	if ( '' === $key ) {
		return null;
	}
	$res = wp_remote_get( 'https://api.bunny.net/' . ltrim( $path, '/' ), array(
		'timeout' => 20,
		'headers' => array(
			'AccessKey' => $key,
			'accept'    => 'application/json',
		),
	) );
	$name = strtok( $path, '?' );
	if ( is_wp_error( $res ) ) {
		maypiano_stats_bunny_note( $name . ': ' . $res->get_error_message() );
		return null;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) ) {
		$why = is_array( $body ) ? (string) ( $body['Message'] ?? $body['message'] ?? '' ) : '';
		maypiano_stats_bunny_note( $name . ': HTTP ' . $code . ( '' !== $why ? ' (' . mb_substr( $why, 0, 160 ) . ')' : '' ) );
		return null;
	}
	return $body;
}

/** Gigabytes sent per day by the video library's delivery zone, or null without the account key. */
function maypiano_stats_bunny_bandwidth( $from ) {
	$library = absint( get_option( 'maypiano_bunny_library' ) );
	$info    = $library ? maypiano_stats_bunny_account( 'videolibrary/' . $library ) : null;
	if ( empty( $info['PullZoneId'] ) ) {
		if ( $info ) {
			maypiano_stats_bunny_note( 'videolibrary: không có PullZoneId' );
		}
		return null;
	}
	// Bunny refuses long or oddly written ranges: ask for plain dates, at most the last 30 days.
	$from  = max( $from, gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );
	$stats = maypiano_stats_bunny_account( 'statistics?hourly=false&pullZone=' . (int) $info['PullZoneId'] . '&dateFrom=' . $from . '&dateTo=' . gmdate( 'Y-m-d' ) );
	if ( ! $stats ) {
		return null;
	}
	if ( ! isset( $stats['BandwidthUsedChart'] ) ) {
		maypiano_stats_bunny_note( 'statistics: không có BandwidthUsedChart (có ' . implode( ', ', array_slice( array_keys( $stats ), 0, 12 ) ) . ')' );
		return null;
	}
	$out = array();
	foreach ( (array) $stats['BandwidthUsedChart'] as $when => $bytes ) {
		$out[ substr( (string) $when, 0, 10 ) ] = round( (float) $bytes / 1e9, 3 );
	}
	maypiano_stats_bunny_note( sprintf( 'vùng phát %d: %s byte cả khoảng', (int) $info['PullZoneId'], number_format( (float) ( $stats['TotalBandwidthUsed'] ?? array_sum( (array) $stats['BandwidthUsedChart'] ) ), 0, '', '.' ) ) );
	return $out;
}

/** Plays, minutes watched and gigabytes sent per day across the whole Bunny library. */
function maypiano_stats_bunny_days() {
	$chart = maypiano_stats_bunny_chart( maypiano_stats_since() );
	if ( null === $chart ) {
		return null;
	}
	$gb  = (array) maypiano_stats_bunny_bandwidth( maypiano_stats_since() );
	$out = array();
	foreach ( $chart as $day => $v ) {
		$out[] = array( 'day' => $day, 'plays' => (int) ( $v['plays'] ?? 0 ), 'minutes' => (int) round( ( $v['seconds'] ?? 0 ) / 60 ), 'gb' => (float) ( $gb[ $day ] ?? 0 ) );
	}
	return $out;
}

/**
 * What Bunny has charged the account this month, in US dollars, split into traffic and the rest, with the dollar
 * rate the site's prices use. Null without the account key.
 */
function maypiano_stats_bunny_bill() {
	$bill = maypiano_stats_bunny_account( 'billing' );
	if ( ! $bill || ! isset( $bill['ThisMonthCharges'] ) ) {
		return null;
	}
	$traffic = 0.0;
	foreach ( $bill as $key => $value ) {
		if ( is_numeric( $value ) && preg_match( '/^MonthlyCharges.*Traffic$/', (string) $key ) ) {
			$traffic += (float) $value;
		}
	}
	$base = maypiano_fx_base();
	return array(
		array(
			'month'       => wp_date( 'Y-m' ),
			'charges_usd' => round( (float) $bill['ThisMonthCharges'], 4 ),
			'traffic_usd' => round( $traffic, 4 ),
			'balance_usd' => round( (float) ( $bill['Balance'] ?? 0 ), 2 ),
			'usd_vnd'     => (float) $base['USD'],
			'note'        => maypiano_stats_bunny_note(),
		),
	);
}

/**
 * Plays per day of every lesson that has a Bunny video. Each run asks Bunny about a few videos (the whole history
 * the first time, the last week after that) and keeps what it learned, so a full round takes a few hours.
 */
function maypiano_stats_lessons() {
	$lessons = get_posts( array(
		'post_type'      => 'lesson',
		'post_status'    => array( 'publish', 'private', 'draft' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_maypiano_bunny', // phpcs:ignore WordPress.DB.SlowDBQuery
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );
	if ( ! $lessons ) {
		return array();
	}
	$known  = (array) get_option( 'maypiano_stats_videos', array() );
	$offset = (int) get_option( 'maypiano_stats_video_offset', 0 ) % count( $lessons );
	$batch  = array_slice( array_merge( array_slice( $lessons, $offset ), array_slice( $lessons, 0, $offset ) ), 0, MAYPIANO_STATS_VIDEO_RUN );
	foreach ( $batch as $lesson_id ) {
		$guid  = (string) get_post_meta( $lesson_id, '_maypiano_bunny', true );
		$from  = isset( $known[ $guid ] ) ? wp_date( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ) : maypiano_stats_since();
		$chart = maypiano_stats_bunny_chart( $from, $guid );
		if ( null === $chart ) {
			continue;
		}
		foreach ( $chart as $day => $v ) {
			$known[ $guid ][ $day ] = (int) ( $v['plays'] ?? 0 );
		}
	}
	update_option( 'maypiano_stats_video_offset', $offset + count( $batch ), false );

	$since = maypiano_stats_since();
	$out   = array();
	foreach ( $lessons as $lesson_id ) {
		$guid = (string) get_post_meta( $lesson_id, '_maypiano_bunny', true );
		if ( empty( $known[ $guid ] ) ) {
			continue;
		}
		$course = function_exists( 'tutor_utils' ) ? (int) tutor_utils()->get_course_id_by( 'lesson', $lesson_id ) : 0;
		$title  = html_entity_decode( get_the_title( $lesson_id ) );
		$name   = $course ? html_entity_decode( get_the_title( $course ) ) : '';
		foreach ( $known[ $guid ] as $day => $n ) {
			if ( $day < $since ) {
				unset( $known[ $guid ][ $day ] );
			} elseif ( $n > 0 ) {
				$out[] = array( 'day' => $day, 'course' => $name, 'lesson' => $title, 'plays' => $n );
			}
		}
	}
	update_option( 'maypiano_stats_videos', $known, false );
	usort( $out, function ( $a, $b ) {
		return strcmp( $a['day'], $b['day'] );
	} );
	return $out;
}

/** Counts everything and keeps each set. A set that cannot be read this time keeps its last rows. */
function maypiano_stats_refresh() {
	$errors = array();
	maypiano_stats_save( 'don-hang', 'Đơn hàng mỗi ngày', maypiano_stats_orders() );
	maypiano_stats_save( 'ghi-danh', 'Ghi danh khóa học mỗi ngày', maypiano_stats_enrolments() );
	maypiano_stats_save( 'hoc-vien-moi', 'Học viên mới mỗi ngày', maypiano_stats_signups() );

	$sets = array(
		'nguon-khach' => array( 'Lượt xem theo nguồn', 'maypiano_stats_sources', 'Không đọc được thống kê nguồn khách.' ),
		'quoc-gia'    => array( 'Lượt xem theo nước', 'maypiano_stats_countries', 'Không đọc được thống kê theo nước.' ),
	);
	if ( maypiano_bunny_ready() ) {
		$sets['bunny-ngay'] = array( 'Video Bunny mỗi ngày', 'maypiano_stats_bunny_days', 'Không đọc được thống kê Bunny.' );
		if ( '' !== trim( (string) get_option( 'maypiano_bunny_account', '' ) ) ) {
			$sets['bunny-tien'] = array( 'Tiền Bunny tháng này', 'maypiano_stats_bunny_bill', 'Không đọc được hóa đơn Bunny. Kiểm tra khóa API tài khoản.' );
		}
	}
	foreach ( $sets as $slug => $set ) {
		$rows = call_user_func( $set[1] );
		if ( null === $rows ) {
			$errors[] = $set[2];
		} else {
			maypiano_stats_save( $slug, $set[0], $rows );
		}
	}

	if ( maypiano_bunny_ready() ) {
		maypiano_stats_save( 'bai-duoc-xem', 'Lượt xem từng bài', maypiano_stats_lessons() );
	} else {
		$errors[] = 'Chưa đủ ba khóa Bunny.';
	}
	update_option( 'maypiano_stats_last', array( 'time' => time(), 'errors' => $errors ), false );
}

add_action( 'maypiano_stats_hourly', 'maypiano_stats_refresh' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'maypiano_stats_hourly' ) ) {
		wp_schedule_event( time() + 60, 'hourly', 'maypiano_stats_hourly' );
	}
} );
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'maypiano_stats_hourly' );
} );

/** "Update now" button on the settings page. */
add_action( 'admin_post_maypiano_stats_refresh', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Không có quyền.' );
	}
	check_admin_referer( 'maypiano_stats_refresh' );
	maypiano_stats_refresh();
	wp_safe_redirect( admin_url( 'options-general.php?page=maypiano#so-lieu' ) );
	exit;
} );

/** The numbers block on the settings page. */
function maypiano_stats_settings_section() {
	$last = (array) get_option( 'maypiano_stats_last', array() );
	echo '<h2 id="so-lieu">Số liệu cho bảng theo dõi</h2>';
	echo '<p>Mỗi giờ site đếm đơn hàng, học viên mới, lượt ghi danh, nguồn khách, lượt xem và lưu lượng video Bunny, tiền Bunny tính, rồi giữ lại để bảng số liệu đọc. Chỉ người quản trị đọc được.</p>';
	if ( ! empty( $last['time'] ) ) {
		printf( '<p>Lần đếm gần nhất: %s.</p>', esc_html( wp_date( 'd/m/Y H:i', (int) $last['time'] ) ) );
		foreach ( (array) ( $last['errors'] ?? array() ) as $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}
	} else {
		echo '<p>Chưa đếm lần nào.</p>';
	}
	printf(
		'<form method="post" action="%s" style="margin-top:12px">%s<input type="hidden" name="action" value="maypiano_stats_refresh"><button class="button">Đếm lại ngay</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'maypiano_stats_refresh', '_wpnonce', true, false ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}
