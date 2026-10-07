<?php
/**
 * Text size on the learner's pages: a small "A− A+" control in the top bar of the learner's own pages and of the lesson page.
 * It works on Tutor LMS's own text-size setting (kept per learner), so the choice in Cài đặt > Tùy chọn and this control always agree.
 * Normal is 100%. Every font size in the theme is in rem, so the whole page follows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The sizes a learner can pick, in percent. 100 is normal. */
function maypiano_text_sizes() {
	return array( 80, 100, 120, 140, 160 );
}

/** Where Tutor LMS keeps a learner's choices. */
function maypiano_text_size_key() {
	return ( class_exists( '\TUTOR\UserPreference' ) && defined( '\TUTOR\UserPreference::META_KEY' ) ) ? \TUTOR\UserPreference::META_KEY : 'tutor_user_preferences';
}

/** The size this learner has now. */
function maypiano_text_size_now( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	$prefs   = $user_id ? get_user_meta( $user_id, maypiano_text_size_key(), true ) : array();
	$size    = is_array( $prefs ) && isset( $prefs['font_scale'] ) ? (int) $prefs['font_scale'] : 100;
	return in_array( $size, maypiano_text_sizes(), true ) ? $size : 100;
}

/** The settings page offers the same sizes as the control. */
add_filter( 'tutor_user_preference_font_scale_values', 'maypiano_text_sizes' );

/** A size from outside this list (saved before the list was set) reads as normal everywhere. */
add_filter( 'tutor_user_preference_data', function ( $prefs ) {
	if ( is_array( $prefs ) && isset( $prefs['font_scale'] ) && ! in_array( (int) $prefs['font_scale'], maypiano_text_sizes(), true ) ) {
		$prefs['font_scale'] = 100;
	}
	return $prefs;
} );

/** Once: every account goes back to the normal size. Learners then pick their own with the control. */
add_action( 'init', function () {
	if ( '1' === get_option( 'maypiano_text_size_reset' ) ) {
		return;
	}
	update_option( 'maypiano_text_size_reset', '1', false );
	$key = maypiano_text_size_key();
	foreach ( get_users( array( 'meta_key' => $key, 'fields' => 'ID' ) ) as $user_id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
		$prefs = get_user_meta( $user_id, $key, true );
		if ( is_array( $prefs ) && isset( $prefs['font_scale'] ) && 100 !== (int) $prefs['font_scale'] ) {
			$prefs['font_scale'] = 100;
			update_user_meta( $user_id, $key, $prefs );
		}
	}
}, 20 );

/** The control itself. */
function maypiano_text_size_control() {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$GLOBALS['maypiano_text_size_shown'] = true;
	$sizes = maypiano_text_sizes();
	$now   = maypiano_text_size_now();
	return '<div class="mp-size" role="group" aria-label="Cỡ chữ" data-now="' . esc_attr( $now ) . '">'
		. '<button type="button" class="mp-size-down" data-step="-1" aria-label="Chữ nhỏ hơn" title="Chữ nhỏ hơn"' . ( $now <= min( $sizes ) ? ' disabled' : '' ) . '><span aria-hidden="true">A</span></button>'
		. '<button type="button" class="mp-size-up" data-step="1" aria-label="Chữ to hơn" title="Chữ to hơn"' . ( $now >= max( $sizes ) ? ' disabled' : '' ) . '><span aria-hidden="true">A</span></button>'
		. '<span class="mp-size-say" role="status" aria-live="polite"></span>'
		. '</div>';
}

/** The learner's own pages: beside the account button. */
add_action( 'tutor_dashboard/before_header_button', function () {
	echo maypiano_text_size_control(); // phpcs:ignore WordPress.Security.EscapeOutput
} );

/** The lesson page: at the right end of the top bar. */
add_action( 'tutor_load_template_before', function ( $template ) {
	if ( 'learning-area.components.header' === $template ) {
		ob_start();
	}
}, 10, 1 );
add_action( 'tutor_load_template_after', function ( $template ) {
	if ( 'learning-area.components.header' !== $template ) {
		return;
	}
	$html    = (string) ob_get_clean();
	$control = maypiano_text_size_control();
	// The learner's profile menu sits after it, as on every other page.
	$control .= function_exists( 'maypiano_me_menu' ) ? maypiano_me_menu() : '';
	if ( '' !== $control ) {
		$with = preg_replace( '/<\/div>(\s*)<div class="tutor-learning-header-toggle-mobile">/', $control . '</div>$1<div class="tutor-learning-header-toggle-mobile">', $html, 1, $done );
		if ( $done ) {
			$html = $with;
		}
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- Tutor LMS's own bar, already escaped there.
}, 10, 1 );

/** Saves the learner's choice. */
add_action( 'wp_ajax_maypiano_text_size', function () {
	check_ajax_referer( 'maypiano_text_size', 'pass' );
	$size = isset( $_POST['size'] ) ? (int) $_POST['size'] : 0;
	if ( ! in_array( $size, maypiano_text_sizes(), true ) ) {
		wp_send_json_error( null, 400 );
	}
	$key   = maypiano_text_size_key();
	$prefs = get_user_meta( get_current_user_id(), $key, true );
	$prefs = is_array( $prefs ) ? $prefs : array();
	$prefs['font_scale'] = $size;
	update_user_meta( get_current_user_id(), $key, $prefs );
	wp_send_json_success( array( 'size' => $size ) );
} );

add_action( 'wp_footer', function () {
	if ( empty( $GLOBALS['maypiano_text_size_shown'] ) ) {
		return;
	}
	?>
<script>
(function () {
	var sizes = <?php echo wp_json_encode( maypiano_text_sizes() ); ?>;
	var boxes = document.querySelectorAll('.mp-size');
	if (!boxes.length) { return; }
	var now = parseInt(boxes[0].getAttribute('data-now'), 10) || 100;
	var timer = 0;
	function show() {
		var style = document.getElementById('tutor-font-scale');
		if (!style) {
			style = document.createElement('style');
			style.id = 'tutor-font-scale';
			document.head.appendChild(style);
		}
		style.textContent = ':root { font-size: ' + (16 * now / 100) + 'px; }';
		boxes.forEach(function (box) {
			box.querySelector('.mp-size-down').disabled = now <= sizes[0];
			box.querySelector('.mp-size-up').disabled = now >= sizes[sizes.length - 1];
			box.querySelector('.mp-size-say').textContent = 'Cỡ chữ ' + now + '%' + (100 === now ? ' (bình thường)' : '');
		});
	}
	function save() {
		var data = new URLSearchParams();
		data.set('action', 'maypiano_text_size');
		data.set('pass', <?php echo wp_json_encode( wp_create_nonce( 'maypiano_text_size' ) ); ?>);
		data.set('size', now);
		fetch(<?php echo wp_json_encode( esc_url_raw( admin_url( 'admin-ajax.php' ) ) ); ?>, { method: 'POST', credentials: 'same-origin', body: data, keepalive: true }).catch(function () {});
	}
	document.addEventListener('click', function (e) {
		var button = e.target.closest ? e.target.closest('.mp-size button') : null;
		if (!button || button.disabled) { return; }
		e.preventDefault();
		e.stopPropagation();
		var at = sizes.indexOf(now);
		if (at < 0) { at = sizes.indexOf(100); }
		at = Math.max(0, Math.min(sizes.length - 1, at + parseInt(button.getAttribute('data-step'), 10)));
		if (sizes[at] === now) { return; }
		now = sizes[at];
		show();
		clearTimeout(timer);
		timer = setTimeout(save, 400);
	}, true);
})();
</script>
	<?php
}, 97 );
