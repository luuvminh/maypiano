<?php
/**
 * Courses on sale, prices by region, and the link to WooCommerce products and Tutor LMS courses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The courses the sign-up page sells. The key is what the page sends back when an order is placed.
 * "match" finds the WooCommerce product by its title when the owner has not picked one in the settings.
 */
function maypiano_catalog() {
	return array(
		'solo-can-ban'  => array(
			'name'   => 'Piano Solo Căn Bản',
			'vnd'    => 899000,
			'match'  => 'solo căn bản',
			'course' => 'piano-solo-can-ban',
		),
		'cover-40'      => array(
			'name'   => 'Piano Cover 40 Bài Nhạc Việt',
			'vnd'    => 1499000,
			'match'  => 'cover',
			'course' => 'piano-cover-nhac-viet',
		),
		'solo-nang-cao' => array(
			'name'   => 'Piano Solo Nâng Cao',
			'vnd'    => 2000000,
			'usd'    => 100,
			'match'  => 'solo nâng cao',
			'course' => 'piano-solo-nang-cao',
		),
		'dem-hat'       => array(
			'name'   => 'Đệm Hát Piano',
			'vnd'    => 799000,
			'match'  => 'đệm hát piano',
			'course' => 'dem-hat-piano',
		),
		'thanh-ca'      => array(
			'name'   => 'Đệm Hát Thánh Ca',
			'vnd'    => 799000,
			'match'  => 'thánh ca',
			'course' => 'piano-dem-hat-thanh-ca',
		),
	);
}

/** Regions the site sells to, each with its currency. */
function maypiano_regions() {
	return array(
		'VN'   => 'VND',
		'AU'   => 'AUD',
		'INTL' => 'USD',
	);
}

function maypiano_currency_for( $region ) {
	$regions = maypiano_regions();
	return isset( $regions[ $region ] ) ? $regions[ $region ] : 'VND';
}

/** Country of the visitor, or '' when the server cannot tell. */
function maypiano_visitor_country() {
	foreach ( array( 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE' ) as $header ) {
		if ( ! empty( $_SERVER[ $header ] ) ) {
			$code = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 0, 2 ) );
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code ) {
				return $code;
			}
		}
	}
	if ( class_exists( 'WC_Geolocation' ) ) {
		$geo = WC_Geolocation::geolocate_ip( '', true, true );
		if ( ! empty( $geo['country'] ) ) {
			return strtoupper( $geo['country'] );
		}
	}
	return '';
}

/** Region for a request: the one the visitor picked, otherwise from where they are. Vietnam when unknown. */
function maypiano_region( $picked = '' ) {
	$picked = strtoupper( (string) $picked );
	if ( isset( maypiano_regions()[ $picked ] ) ) {
		return $picked;
	}
	$country = maypiano_visitor_country();
	if ( '' === $country || 'VN' === $country ) {
		return 'VN';
	}
	return 'AU' === $country ? 'AU' : 'INTL';
}

/** The WooCommerce product that stands for a course, or null. */
function maypiano_product_for( $key ) {
	static $found = array();
	if ( array_key_exists( $key, $found ) ) {
		return $found[ $key ];
	}
	$found[ $key ] = null;
	$catalog       = maypiano_catalog();
	if ( ! isset( $catalog[ $key ] ) || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}
	$picked = (array) get_option( 'maypiano_products', array() );
	if ( ! empty( $picked[ $key ] ) ) {
		$product = wc_get_product( absint( $picked[ $key ] ) );
		if ( $product ) {
			$found[ $key ] = $product;
			return $product;
		}
	}
	$needle = $catalog[ $key ]['match'];
	foreach ( maypiano_all_products() as $product ) {
		if ( false !== mb_strpos( mb_strtolower( $product->get_name() ), $needle ) ) {
			$found[ $key ] = $product;
			break;
		}
	}
	return $found[ $key ];
}

/** Every product in the shop, including ones not yet published. */
function maypiano_all_products() {
	static $all = null;
	if ( null === $all ) {
		$all = function_exists( 'wc_get_products' ) ? wc_get_products( array(
			'limit'   => 100,
			'status'  => array( 'publish', 'private', 'draft' ),
			'orderby' => 'ID',
			'order'   => 'ASC',
		) ) : array();
	}
	return $all;
}

/** The Tutor LMS course a buyer of this catalog entry gets into, or 0. */
function maypiano_course_for( $key, $product_id = 0 ) {
	$catalog = maypiano_catalog();
	if ( ! isset( $catalog[ $key ] ) || ! post_type_exists( 'courses' ) ) {
		return 0;
	}
	$statuses = array( 'publish', 'private', 'draft' );
	if ( $product_id ) {
		$linked = get_posts( array(
			'post_type'   => 'courses',
			'post_status' => $statuses,
			'meta_key'    => '_tutor_course_product_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => (string) $product_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			'numberposts' => 1,
			'fields'      => 'ids',
		) );
		if ( $linked ) {
			return (int) $linked[0];
		}
	}
	$by_slug = get_posts( array(
		'post_type'   => 'courses',
		'post_status' => $statuses,
		'name'        => $catalog[ $key ]['course'],
		'numberposts' => 1,
		'fields'      => 'ids',
	) );
	if ( $by_slug ) {
		return (int) $by_slug[0];
	}
	$needle = $catalog[ $key ]['match'];
	foreach ( get_posts( array( 'post_type' => 'courses', 'post_status' => $statuses, 'numberposts' => 100 ) ) as $course ) {
		if ( false !== mb_strpos( mb_strtolower( $course->post_title ), $needle ) ) {
			return (int) $course->ID;
		}
	}
	return 0;
}

/**
 * Price of one course in one currency, or null when the owner has not set one.
 * Order of trust: the price table in the settings, then (VND only) the product price, then the launch price.
 */
function maypiano_price( $key, $currency ) {
	$catalog = maypiano_catalog();
	if ( ! isset( $catalog[ $key ] ) ) {
		return null;
	}
	$table = (array) get_option( 'maypiano_prices', array() );
	if ( isset( $table[ $key ][ $currency ] ) && (float) $table[ $key ][ $currency ] > 0 ) {
		return (float) $table[ $key ][ $currency ];
	}
	$fallback = strtolower( $currency );
	if ( 'VND' === $currency ) {
		$product = maypiano_product_for( $key );
		if ( $product && (float) $product->get_price( 'edit' ) > 0 ) {
			return (float) $product->get_price( 'edit' );
		}
	}
	return isset( $catalog[ $key ][ $fallback ] ) ? (float) $catalog[ $key ][ $fallback ] : null;
}

/** Money as the site writes it: 899.000đ, A$59, US$100. */
function maypiano_money( $amount, $currency ) {
	if ( 'VND' === $currency ) {
		return number_format( (float) $amount, 0, ',', '.' ) . 'đ';
	}
	$text = number_format( (float) $amount, 2, '.', ',' );
	$text = preg_replace( '/\.00$/', '', $text );
	return ( 'AUD' === $currency ? 'A$' : 'US$' ) . $text;
}
