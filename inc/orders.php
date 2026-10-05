<?php
/**
 * The sign-up flow behind the page: placing an order, telling how to pay, confirming payment,
 * creating the learner's account and opening the courses.
 *
 * Every order is a WooCommerce order (WooCommerce > Orders). Marking it "Completed" is what
 * confirms payment and opens the courses in Tutor LMS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** True for orders placed through the sign-up page. */
function maypiano_is_ours( $order ) {
	return $order instanceof WC_Order && (bool) $order->get_meta( '_mp_flow' );
}

/** What the customer writes in the transfer so the payment can be matched to the order. */
function maypiano_memo( $order ) {
	return 'MP' . $order->get_id();
}

function maypiano_order_code( $order ) {
	return 'MP-' . $order->get_id();
}

function maypiano_order_url( $order ) {
	return add_query_arg( array( 'don' => $order->get_id(), 'key' => $order->get_order_key() ), home_url( '/dang-ky/' ) );
}

/** Where a learner goes to study after signing in. */
function maypiano_learn_url() {
	if ( function_exists( 'tutor_utils' ) ) {
		$url = tutor_utils()->tutor_dashboard_url();
		if ( $url ) {
			return $url;
		}
	}
	return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
}

/** Ways to pay offered to one region. Card shows in test mode only to a signed-in owner. */
function maypiano_methods( $region ) {
	$methods = array( 'VN' === $region ? 'bank' : 'paypal' );
	$mode    = maypiano_card_mode();
	if ( 'live' === $mode || ( 'test' === $mode && current_user_can( 'manage_options' ) ) ) {
		$methods[] = 'card';
	}
	return $methods;
}

/** PayPal link with the amount filled in, or '' when only an email (or nothing) is set. */
function maypiano_paypal_url( $amount, $currency ) {
	$handle = maypiano_setting( 'maypiano_paypal' );
	if ( '' === $handle || is_email( $handle ) ) {
		return '';
	}
	$handle = preg_replace( '#^https?://(www\.)?(paypal\.me|paypal\.com/paypalme)/#i', '', $handle );
	$handle = preg_replace( '/[^A-Za-z0-9._-]/', '', strtok( $handle, '/?' ) );
	if ( '' === $handle ) {
		return '';
	}
	$url = 'https://www.paypal.com/paypalme/' . $handle;
	if ( $amount > 0 && 'VND' !== $currency ) {
		$url .= '/' . rawurlencode( rtrim( rtrim( number_format( (float) $amount, 2, '.', '' ), '0' ), '.' ) . $currency );
	}
	return $url;
}

/** Where the order stands, in the page's words. */
function maypiano_order_state( $order ) {
	$status = $order->get_status();
	if ( 'completed' === $status ) {
		return 'paid';
	}
	if ( 'failed' === $status ) {
		return 'failed';
	}
	if ( in_array( $status, array( 'cancelled', 'refunded', 'trash' ), true ) ) {
		return 'cancelled';
	}
	if ( 'processing' === $status || $order->get_meta( '_mp_reported' ) ) {
		return 'reported';
	}
	return 'waiting';
}

/** Everything the page needs to show one order. Only given to someone holding the order key. */
function maypiano_order_view( $order ) {
	$currency = $order->get_currency();
	$method   = $order->get_meta( '_mp_method' );
	$quote    = (bool) $order->get_meta( '_mp_needs_quote' );
	$total    = (float) $order->get_total();
	$items    = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array(
			'key'   => (string) $item->get_meta( '_mp_key' ),
			'name'  => $item->get_name(),
			'price' => (float) $item->get_total(),
		);
	}
	$state = maypiano_order_state( $order );
	$view  = array(
		'id'         => $order->get_id(),
		'key'        => $order->get_order_key(),
		'code'       => maypiano_order_code( $order ),
		'memo'       => maypiano_memo( $order ),
		'state'      => $state,
		'method'     => $method,
		'region'     => (string) $order->get_meta( '_mp_region' ),
		'currency'   => $currency,
		'total'      => $total,
		'needsQuote' => $quote,
		'items'      => $items,
		'name'       => $order->get_billing_first_name(),
		'email'      => $order->get_billing_email(),
		'test'       => (bool) $order->get_meta( '_mp_test' ),
		'paypalUrl'  => 'paypal' === $method && ! $quote ? maypiano_paypal_url( $total, $currency ) : '',
		'paypalTo'   => is_email( maypiano_setting( 'maypiano_paypal' ) ) ? maypiano_setting( 'maypiano_paypal' ) : '',
		'payUrl'     => 'card' === $method && 'live' === maypiano_card_mode() && $order->needs_payment() ? $order->get_checkout_payment_url() : '',
	);
	if ( 'paid' === $state ) {
		$view['learnUrl']   = wp_login_url( maypiano_learn_url() );
		$view['newAccount'] = (bool) $order->get_meta( '_mp_new_account' );
		$view['access']     = (string) $order->get_meta( '_mp_access' );
	}
	return $view;
}

/** Finds the order a request is about and checks the caller holds its key. */
function maypiano_request_order( WP_REST_Request $req ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'unavailable', 'Shop is off', array( 'status' => 503 ) );
	}
	$order = wc_get_order( absint( $req->get_param( 'id' ) ) );
	$key   = (string) $req->get_param( 'key' );
	if ( ! maypiano_is_ours( $order ) || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
		return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
	}
	return $order;
}

/** Keeps Tutor LMS from enrolling "nobody" while a guest order is being built; access is opened on payment instead. */
add_filter( 'tutor_wc_should_process_checkout_order_item', function ( $process, $item = null, $order = null ) {
	if ( ! empty( $GLOBALS['maypiano_placing_order'] ) || maypiano_is_ours( $order ) ) {
		return false;
	}
	return $process;
}, 10, 3 );

/**
 * Places an order. Prices are worked out here, never taken from the browser.
 *
 * @return WC_Order|WP_Error
 */
function maypiano_place_order( $args ) {
	$catalog  = maypiano_catalog();
	$region   = maypiano_region( $args['region'] );
	$currency = maypiano_currency_for( $region );
	$keys     = array_values( array_unique( array_filter( (array) $args['items'], function ( $key ) use ( $catalog ) {
		return is_string( $key ) && isset( $catalog[ $key ] );
	} ) ) );
	if ( ! $keys ) {
		return new WP_Error( 'empty', 'No courses picked', array( 'status' => 400 ) );
	}
	if ( ! in_array( $args['method'], maypiano_methods( $region ), true ) ) {
		return new WP_Error( 'method', 'This way to pay is not available', array( 'status' => 400 ) );
	}

	$lines = array();
	$quote = false;
	foreach ( $keys as $key ) {
		$price = maypiano_price( $key, $currency );
		if ( null === $price ) {
			$quote = true;
		}
		$lines[ $key ] = $price;
	}

	$user = get_user_by( 'email', $args['email'] );

	$GLOBALS['maypiano_placing_order'] = true;
	$order                             = wc_create_order( array(
		'status'      => 'pending',
		'customer_id' => $user ? $user->ID : 0,
		'created_via' => 'maypiano',
	) );
	if ( is_wp_error( $order ) ) {
		unset( $GLOBALS['maypiano_placing_order'] );
		return $order;
	}

	$order->set_currency( $currency );
	$order->update_meta_data( '_mp_flow', 1 );
	$order->update_meta_data( '_mp_token', $args['token'] );
	$order->update_meta_data( '_mp_region', $region );
	$order->update_meta_data( '_mp_method', $args['method'] );
	if ( $quote ) {
		$order->update_meta_data( '_mp_needs_quote', 1 );
	}
	foreach ( $lines as $key => $price ) {
		$amount  = $quote ? 0 : $price;
		$product = maypiano_product_for( $key );
		$item    = new WC_Order_Item_Product();
		if ( $product ) {
			$item->set_product( $product );
		}
		$item->set_name( $catalog[ $key ]['name'] );
		$item->set_quantity( 1 );
		$item->set_subtotal( $amount );
		$item->set_total( $amount );
		$item->add_meta_data( '_mp_key', $key, true );
		$order->add_item( $item );
	}
	$order->set_billing_first_name( $args['name'] );
	$order->set_billing_email( $args['email'] );
	$order->set_billing_phone( $args['phone'] );
	$order->set_billing_country( 'INTL' === $region ? '' : $region );
	if ( class_exists( 'WC_Geolocation' ) ) {
		$order->set_customer_ip_address( WC_Geolocation::get_ip_address() );
	}
	$titles = array(
		'bank'   => array( 'bacs', 'Chuyển khoản ngân hàng' ),
		'paypal' => array( 'mp_paypal', 'PayPal' ),
		'card'   => array( 'mp_card', 'live' === maypiano_card_mode() ? 'Thẻ' : 'Thẻ (chạy thử)' ),
	);
	$order->set_payment_method( $titles[ $args['method'] ][0] );
	$order->set_payment_method_title( $titles[ $args['method'] ][1] );
	$order->calculate_totals( false );
	$order->save();

	if ( 'card' === $args['method'] ) {
		if ( 'live' !== maypiano_card_mode() ) {
			$order->update_meta_data( '_mp_test', 1 );
			$order->add_order_note( 'ĐƠN CHẠY THỬ thanh toán thẻ. Không có tiền thật.' );
			$order->save();
		}
	} else {
		$order->update_status( 'on-hold', $quote ? 'Chưa có giá cho vùng của khách. Cần báo giá qua email.' : 'Chờ khách trả tiền.' );
	}
	unset( $GLOBALS['maypiano_placing_order'] );

	if ( 'card' !== $args['method'] ) {
		maypiano_mail_order_received( $order );
		maypiano_mail_owner_new_order( $order );
	}
	return $order;
}

/** An order placed moments ago with the same token, so a double tap or a retry never makes two. */
function maypiano_order_by_token( $token ) {
	$found = wc_get_orders( array(
		'limit'      => 1,
		'meta_key'   => '_mp_token', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value' => $token, // phpcs:ignore WordPress.DB.SlowDBQuery
		'status'     => array_keys( wc_get_order_statuses() ),
	) );
	return $found ? $found[0] : null;
}

/** Paid orders go straight to Completed: courses are not shipped, there is nothing left to do. */
add_filter( 'woocommerce_payment_complete_order_status', function ( $status, $order_id = 0, $order = null ) {
	$order = $order ? $order : wc_get_order( $order_id );
	return maypiano_is_ours( $order ) ? 'completed' : $status;
}, 20, 3 );

add_action( 'woocommerce_order_status_processing', function ( $order_id, $order = null ) {
	$order = $order ? $order : wc_get_order( $order_id );
	if ( maypiano_is_ours( $order ) ) {
		$order->update_status( 'completed', 'Đã nhận tiền, tự chuyển sang hoàn tất.' );
	}
}, 20, 2 );

/** After a real card payment through WooCommerce, the customer comes back to the sign-up page. */
add_filter( 'woocommerce_get_checkout_order_received_url', function ( $url, $order = null ) {
	return maypiano_is_ours( $order ) ? maypiano_order_url( $order ) : $url;
}, 20, 2 );

/** The site's own emails replace WooCommerce's for these orders, so nobody gets two. */
foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_failed_order' ) as $maypiano_email_id ) {
	add_filter( 'woocommerce_email_enabled_' . $maypiano_email_id, function ( $enabled, $order = null ) {
		return maypiano_is_ours( $order ) ? false : $enabled;
	}, 20, 2 );
}
unset( $maypiano_email_id );
add_filter( 'woocommerce_email_enabled_failed_order', function ( $enabled, $order = null ) {
	return maypiano_is_ours( $order ) && $order->get_meta( '_mp_test' ) ? false : $enabled;
}, 20, 2 );

/**
 * Payment confirmed, step 1 (before Tutor LMS reacts): make sure the buyer has an account
 * and is put on each course's list.
 */
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to, $order = null ) {
	$order = $order ? $order : wc_get_order( $order_id );
	if ( 'completed' !== $to || ! maypiano_is_ours( $order ) || $order->get_meta( '_mp_fulfilled' ) ) {
		return;
	}
	$user_id = (int) $order->get_customer_id();
	if ( ! $user_id ) {
		$email = $order->get_billing_email();
		$user  = get_user_by( 'email', $email );
		if ( $user ) {
			$user_id = (int) $user->ID;
		} else {
			$name = $order->get_billing_first_name();
			add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
			$user_id = wc_create_new_customer( $email, wc_create_new_customer_username( $email ), wp_generate_password( 24 ), array(
				'first_name'   => $name,
				'display_name' => $name,
			) );
			remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
			if ( is_wp_error( $user_id ) ) {
				$order->add_order_note( 'KHÔNG tạo được tài khoản học cho khách: ' . $user_id->get_error_message() );
				return;
			}
			$order->update_meta_data( '_mp_new_account', 1 );
		}
		$order->set_customer_id( $user_id );
		$order->save();
	}
	if ( ! function_exists( 'tutor_utils' ) ) {
		return;
	}
	foreach ( $order->get_items() as $item ) {
		$course_id = maypiano_course_for( (string) $item->get_meta( '_mp_key' ), (int) $item->get_product_id() );
		if ( $course_id ) {
			maypiano_enrol( $course_id, $user_id, $order_id, false );
		}
	}
}, 5, 4 );

/**
 * Payment confirmed, step 2 (after Tutor LMS reacted): check every course is really open,
 * write down what happened and tell the customer.
 */
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to ) {
	$order = wc_get_order( $order_id );
	if ( 'completed' !== $to || ! maypiano_is_ours( $order ) || $order->get_meta( '_mp_fulfilled' ) ) {
		return;
	}
	$user_id = (int) $order->get_customer_id();
	$opened  = array();
	$missing = array();
	foreach ( $order->get_items() as $item ) {
		$course_id = $user_id ? maypiano_course_for( (string) $item->get_meta( '_mp_key' ), (int) $item->get_product_id() ) : 0;
		if ( $course_id && function_exists( 'tutor_utils' ) && maypiano_enrol( $course_id, $user_id, $order_id, true ) ) {
			$opened[] = $item->get_name();
		} else {
			$missing[] = $item->get_name();
		}
	}
	$order = wc_get_order( $order_id );
	$order->update_meta_data( '_mp_fulfilled', time() );
	$order->update_meta_data( '_mp_access', $missing ? ( $opened ? 'partial' : 'manual' ) : 'open' );
	$order->save();
	if ( $opened ) {
		$order->add_order_note( 'Đã mở khóa học cho tài khoản #' . $user_id . ': ' . implode( ', ', $opened ) . '.' );
	}
	if ( $missing ) {
		$order->add_order_note( 'CHƯA mở được khóa học, cần ghi danh bằng tay trong Tutor LMS: ' . implode( ', ', $missing ) . '.' );
		maypiano_mail_owner_access_problem( $order, $missing );
	}
	maypiano_mail_access( $order, $user_id, $opened, $missing );
}, 20, 3 );

/**
 * Puts a learner on a course. With $open, also makes sure the place is active.
 *
 * @return int Enrolment id, or 0 when the course could not be opened.
 */
function maypiano_enrol( $course_id, $user_id, $order_id, $open ) {
	global $wpdb;
	$find = function () use ( $wpdb, $course_id, $user_id ) {
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d AND post_author = %d ORDER BY ID DESC LIMIT 1",
			$course_id,
			$user_id
		) );
	};
	$row = $find();
	if ( ! $row ) {
		if ( class_exists( '\Tutor\Models\EnrollmentModel' ) ) {
			\Tutor\Models\EnrollmentModel::do_enroll( $course_id, $order_id, $user_id );
		} else {
			tutor_utils()->do_enroll( $course_id, $order_id, $user_id );
		}
		$row = $find();
	}
	if ( ! $row ) {
		return 0;
	}
	if ( $open && 'completed' !== $row->post_status ) {
		tutor_utils()->course_enrol_status_change( $row->ID, 'completed' );
		clean_post_cache( $row->ID );
		do_action( 'tutor_after_enrolled', $course_id, $user_id, $row->ID );
	}
	return (int) $row->ID;
}

/** Allows a handful of requests per visitor per hour. */
function maypiano_throttled( $bucket, $limit = 10 ) {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'mp_' . $bucket . '_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= $limit ) {
		return true;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return false;
}

add_action( 'rest_api_init', function () {
	$public = array( 'methods' => 'POST', 'permission_callback' => '__return_true' );

	/** Prices, region and ways to pay. Asked for by the page on load, so a cached page still gets fresh answers. */
	register_rest_route( 'maypiano/v1', '/config', $public + array(
		'callback' => function ( WP_REST_Request $req ) {
			nocache_headers();
			$prices = array();
			foreach ( maypiano_catalog() as $key => $course ) {
				foreach ( maypiano_regions() as $currency ) {
					$prices[ $key ][ $currency ] = maypiano_price( $key, $currency );
				}
			}
			$methods = array();
			foreach ( maypiano_regions() as $region => $currency ) {
				$methods[ $region ] = maypiano_methods( $region );
			}
			$mode = maypiano_card_mode();
			return array(
				'ready'      => function_exists( 'wc_create_order' ),
				'region'     => maypiano_region( $req->get_param( 'region' ) ),
				'prices'     => $prices,
				'methods'    => $methods,
				'cardMode'   => 'test' === $mode && ! current_user_can( 'manage_options' ) ? 'off' : $mode,
				'bank'       => array(
					'bin'     => maypiano_setting( 'maypiano_bank_bin' ),
					'account' => maypiano_setting( 'maypiano_account' ),
					'name'    => maypiano_setting( 'maypiano_account_name' ),
					'qr'      => esc_url_raw( maypiano_setting( 'maypiano_qr' ) ),
				),
				'processing' => maypiano_setting( 'maypiano_processing' ),
				'support'    => maypiano_setting( 'maypiano_support' ),
			);
		},
	) );

	register_rest_route( 'maypiano/v1', '/order', $public + array(
		'callback' => function ( WP_REST_Request $req ) {
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
					return maypiano_order_view( $again );
				}
			} else {
				$token = wp_generate_uuid4();
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$name  = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			if ( ! is_email( $email ) || '' === $name || mb_strlen( $name ) > 80 ) {
				return new WP_Error( 'invalid', 'Missing name or email', array( 'status' => 400 ) );
			}
			// Counted per network address. Mobile carriers put many people behind one address, so the limit is roomy.
			if ( maypiano_throttled( 'order', 30 ) ) {
				return new WP_Error( 'too_many', 'Too many requests', array( 'status' => 429 ) );
			}
			$order = maypiano_place_order( array(
				'items'  => (array) $req->get_param( 'items' ),
				'name'   => $name,
				'email'  => $email,
				'phone'  => substr( preg_replace( '/[^0-9+ ]/', '', (string) $req->get_param( 'phone' ) ), 0, 20 ),
				'region' => (string) $req->get_param( 'region' ),
				'method' => sanitize_key( (string) $req->get_param( 'method' ) ),
				'token'  => $token,
			) );
			return is_wp_error( $order ) ? $order : maypiano_order_view( $order );
		},
	) );

	register_rest_route( 'maypiano/v1', '/order-status', $public + array(
		'callback' => function ( WP_REST_Request $req ) {
			nocache_headers();
			$order = maypiano_request_order( $req );
			return is_wp_error( $order ) ? $order : maypiano_order_view( $order );
		},
	) );

	/** The customer says the money is sent. Nothing is opened yet; the owner is asked to check. */
	register_rest_route( 'maypiano/v1', '/order-paid', $public + array(
		'callback' => function ( WP_REST_Request $req ) {
			nocache_headers();
			$order = maypiano_request_order( $req );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			if ( 'waiting' === maypiano_order_state( $order ) && 'card' !== $order->get_meta( '_mp_method' ) ) {
				$order->update_meta_data( '_mp_reported', time() );
				$order->save();
				$order->add_order_note( 'Khách báo đã trả tiền. Kiểm tra tài khoản rồi đổi đơn sang Completed.' );
				maypiano_mail_owner_reported( $order );
			}
			return maypiano_order_view( $order );
		},
	) );

	/** The customer steps back before paying, for instance to change the cart. */
	register_rest_route( 'maypiano/v1', '/order-cancel', $public + array(
		'callback' => function ( WP_REST_Request $req ) {
			nocache_headers();
			$order = maypiano_request_order( $req );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			if ( in_array( maypiano_order_state( $order ), array( 'waiting', 'failed' ), true ) && $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
				$order->update_status( 'cancelled', 'Khách tự hủy trên trang đăng ký.' );
			}
			return maypiano_order_view( $order );
		},
	) );

	/** Card payment rehearsal. Owner only, test mode only. No card details exist anywhere in it. */
	register_rest_route( 'maypiano/v1', '/card-test', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return 'test' === maypiano_card_mode() && current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			nocache_headers();
			$order = maypiano_request_order( $req );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			if ( ! $order->get_meta( '_mp_test' ) || ! $order->has_status( array( 'pending', 'failed' ) ) ) {
				return new WP_Error( 'invalid', 'Not a test order awaiting payment', array( 'status' => 400 ) );
			}
			if ( 'success' === $req->get_param( 'outcome' ) ) {
				$order->payment_complete( 'TEST-' . time() );
			} else {
				$order->update_status( 'failed', 'Chạy thử: thẻ bị từ chối.' );
			}
			return maypiano_order_view( wc_get_order( $order->get_id() ) );
		},
	) );

	register_rest_route( 'maypiano/v1', '/subscribe', $public + array(
		'callback' => function ( WP_REST_Request $req ) {
			if ( maypiano_throttled( 'sub' ) ) {
				return new WP_Error( 'too_many', 'Too many requests', array( 'status' => 429 ) );
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'invalid', 'Invalid email', array( 'status' => 400 ) );
			}
			$existing = get_posts( array( 'post_type' => 'mp_sub', 'post_status' => 'private', 'title' => $email, 'numberposts' => 1, 'fields' => 'ids' ) );
			if ( ! $existing ) {
				wp_insert_post( array( 'post_type' => 'mp_sub', 'post_status' => 'private', 'post_title' => $email ) );
			}
			return array( 'ok' => true );
		},
	) );
} );

/** Short note on where one of these orders stands, for the WooCommerce order list. */
function maypiano_order_badge( $order ) {
	if ( ! maypiano_is_ours( $order ) ) {
		return '';
	}
	$test  = $order->get_meta( '_mp_test' ) ? 'CHẠY THỬ · ' : '';
	$state = maypiano_order_state( $order );
	if ( 'paid' === $state ) {
		$access = $order->get_meta( '_mp_access' );
		return $test . ( 'open' === $access ? 'Đã mở khóa học' : 'Đã trả, CẦN GHI DANH TAY' );
	}
	if ( 'reported' === $state ) {
		$at = (int) $order->get_meta( '_mp_reported' );
		return $test . 'KHÁCH BÁO ĐÃ TRẢ' . ( $at ? ' ' . wp_date( 'd/m H:i', $at ) : '' );
	}
	if ( 'waiting' === $state ) {
		return $test . ( $order->get_meta( '_mp_needs_quote' ) ? 'Cần báo giá cho khách' : 'Chờ khách trả' );
	}
	return $test . ( 'failed' === $state ? 'Trả tiền không thành' : 'Đã hủy' );
}

/** Adds that note as a column in WooCommerce > Orders (both the new and the classic order list). */
foreach ( array( 'manage_woocommerce_page_wc-orders_columns', 'manage_edit-shop_order_columns' ) as $maypiano_hook ) {
	add_filter( $maypiano_hook, function ( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['maypiano'] = 'May Piano';
			}
		}
		return isset( $out['maypiano'] ) ? $out : $columns + array( 'maypiano' => 'May Piano' );
	}, 20 );
}
unset( $maypiano_hook );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', function ( $column, $order ) {
	if ( 'maypiano' === $column ) {
		echo esc_html( maypiano_order_badge( $order ) );
	}
}, 10, 2 );
add_action( 'manage_shop_order_posts_custom_column', function ( $column, $post_id ) {
	if ( 'maypiano' === $column ) {
		echo esc_html( maypiano_order_badge( wc_get_order( $post_id ) ) );
	}
}, 10, 2 );
