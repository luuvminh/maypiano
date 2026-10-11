<?php
/**
 * The flag button in the bar: the visitor picks where they are, and every price on the page follows.
 * Same button, same menu and same remembered choice as on the home page and the sign-up page
 * (the choice lives in the browser under maypiano_dang_ky.region). Flags are the home page's drawings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pages with the shared bar that show a price: a course, the sheet-music page, the single-songs page. */
function maypiano_place_shown() {
	return is_singular( 'courses' ) || is_page( array( 'sheet-nhac', 'bai-hat-le' ) );
}

function maypiano_place_flags() {
	return array(
		'VN'   => array( 'Việt Nam', 'VND', '<svg class="mp-flag-svg" width="28" height="20" viewBox="0 0 28 20" aria-hidden="true"><rect width="28" height="20" fill="#DA251D"></rect><polygon points="14.00,4.60 15.29,8.62 19.52,8.61 16.09,11.08 17.41,15.09 14.00,12.60 10.59,15.09 11.91,11.08 8.48,8.61 12.71,8.62" fill="#FFEB00"></polygon></svg>' ),
		'AU'   => array( 'Úc', 'AUD', '<svg class="mp-flag-svg" width="28" height="20" viewBox="0 0 28 20" aria-hidden="true"><rect width="28" height="20" fill="#012169"></rect><path d="M0 0L14 10M14 0L0 10" stroke="#FFFFFF" stroke-width="2.2"></path><path d="M0 0L14 10M14 0L0 10" stroke="#C8102E" stroke-width=".9"></path><path d="M7 0V10M0 5H14" stroke="#FFFFFF" stroke-width="3.4"></path><path d="M7 0V10M0 5H14" stroke="#C8102E" stroke-width="1.9"></path><rect x="14" y="0" width="14" height="10" fill="#012169"></rect><rect x="0" y="10" width="14" height="10" fill="#012169"></rect><polygon points="7.00,12.40 7.51,13.95 9.03,13.38 8.14,14.74 9.53,15.58 7.91,15.73 8.13,17.34 7.00,16.17 5.87,17.34 6.09,15.73 4.47,15.58 5.86,14.74 4.97,13.38 6.49,13.95" fill="#FFFFFF"></polygon><polygon points="21.00,2.30 21.25,3.07 22.02,2.79 21.57,3.47 22.27,3.89 21.46,3.96 21.56,4.77 21.00,4.19 20.44,4.77 20.54,3.96 19.73,3.89 20.43,3.47 19.98,2.79 20.75,3.07" fill="#FFFFFF"></polygon><polygon points="24.60,6.90 24.85,7.67 25.62,7.39 25.17,8.07 25.87,8.49 25.06,8.56 25.16,9.37 24.60,8.79 24.04,9.37 24.14,8.56 23.33,8.49 24.03,8.07 23.58,7.39 24.35,7.67" fill="#FFFFFF"></polygon><polygon points="21.00,14.90 21.25,15.67 22.02,15.39 21.57,16.07 22.27,16.49 21.46,16.56 21.56,17.37 21.00,16.79 20.44,17.37 20.54,16.56 19.73,16.49 20.43,16.07 19.98,15.39 20.75,15.67" fill="#FFFFFF"></polygon><polygon points="17.60,8.10 17.85,8.87 18.62,8.59 18.17,9.27 18.87,9.69 18.06,9.76 18.16,10.57 17.60,9.99 17.04,10.57 17.14,9.76 16.33,9.69 17.03,9.27 16.58,8.59 17.35,8.87" fill="#FFFFFF"></polygon><polygon points="22.90,10.85 23.09,11.33 23.61,11.37 23.21,11.70 23.34,12.21 22.90,11.93 22.46,12.21 22.59,11.70 22.19,11.37 22.71,11.33" fill="#FFFFFF"></polygon></svg>' ),
		'INTL' => array( 'Mỹ và nước khác', 'USD', '<svg class="mp-flag-svg" width="28" height="20" viewBox="0 0 28 20" aria-hidden="true"><rect width="28" height="20" fill="#FFFFFF"></rect><rect y="0.000" width="28" height="2.857" fill="#B22234"></rect><rect y="5.714" width="28" height="2.857" fill="#B22234"></rect><rect y="11.429" width="28" height="2.857" fill="#B22234"></rect><rect y="17.143" width="28" height="2.857" fill="#B22234"></rect><rect width="13" height="11.429" fill="#3C3B6E"></rect><circle cx="2.60" cy="2.40" r=".8" fill="#FFFFFF"></circle><circle cx="6.50" cy="2.40" r=".8" fill="#FFFFFF"></circle><circle cx="10.40" cy="2.40" r=".8" fill="#FFFFFF"></circle><circle cx="2.60" cy="5.70" r=".8" fill="#FFFFFF"></circle><circle cx="6.50" cy="5.70" r=".8" fill="#FFFFFF"></circle><circle cx="10.40" cy="5.70" r=".8" fill="#FFFFFF"></circle><circle cx="2.60" cy="9.00" r=".8" fill="#FFFFFF"></circle><circle cx="6.50" cy="9.00" r=".8" fill="#FFFFFF"></circle><circle cx="10.40" cy="9.00" r=".8" fill="#FFFFFF"></circle></svg>' ),
	);
}

/**
 * The button and its menu. The page's own script asks the site for prices, then calls MayPianoPlace.show( region )
 * so the flag matches the prices on the page; a pick sends the event "maypiano:place" for the page to redraw its prices.
 */
function maypiano_place_menu() {
	if ( ! maypiano_place_shown() ) {
		return '';
	}
	$flags = maypiano_place_flags();
	$html  = '<div class="mp-flag" data-mp-place><button type="button" class="mp-flag-btn" aria-haspopup="true" aria-expanded="false" title="Xem giá cho bạn ở" aria-label="Xem giá cho bạn ở Việt Nam. Bấm để đổi nơi khác.">'
		. '<span class="mp-flag-now">' . $flags['VN'][2] . '</span><svg class="mp-flag-chev" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 4.5L6 8l3.5-3.5"></path></svg></button>'
		. '<div class="mp-flag-menu" role="group" aria-labelledby="mp-gia-vung" hidden><div id="mp-gia-vung" class="mp-flag-head">Xem giá cho bạn ở</div>';
	foreach ( $flags as $id => $flag ) {
		$html .= '<button type="button" class="mp-flag-opt" data-place="' . esc_attr( $id ) . '" data-name="' . esc_attr( $flag[0] ) . '" aria-pressed="' . ( 'VN' === $id ? 'true' : 'false' ) . '">' . $flag[2]
			. '<span class="mp-flag-name">' . esc_html( $flag[0] ) . '</span><span class="mp-flag-cur">' . esc_html( $flag[1] ) . '</span><svg class="mp-flag-tick" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C2456B" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"></path></svg></button>';
	}
	return $html . '</div></div>' . "\n" . '<script>' . maypiano_place_script() . '</script>';
}

function maypiano_place_script() {
	return <<<'JS'
(function () {
	var wrap = document.querySelector('[data-mp-place]');
	if (!wrap) { return; }
	var KEY = 'maypiano_dang_ky', btn = wrap.querySelector('.mp-flag-btn'), menu = wrap.querySelector('.mp-flag-menu'), now = wrap.querySelector('.mp-flag-now');
	var opts = [].slice.call(wrap.querySelectorAll('.mp-flag-opt'));
	var stored = function () { try { return JSON.parse(window.localStorage.getItem(KEY) || '{}') || {}; } catch (e) { return {}; } };
	var known = function (id) { return id === 'VN' || id === 'AU' || id === 'INTL'; };
	var picked = known(stored().region) ? stored().region : '';
	var show = function (id) {
		if (!known(id)) { id = 'VN'; }
		opts.forEach(function (o) {
			var on = o.getAttribute('data-place') === id;
			o.setAttribute('aria-pressed', on ? 'true' : 'false');
			if (on) {
				now.innerHTML = '';
				now.appendChild(o.querySelector('.mp-flag-svg').cloneNode(true));
				btn.setAttribute('aria-label', 'Xem giá cho bạn ở ' + o.getAttribute('data-name') + '. Bấm để đổi nơi khác.');
			}
		});
	};
	var open = function (v) { menu.hidden = !v; btn.setAttribute('aria-expanded', v ? 'true' : 'false'); };
	btn.addEventListener('click', function () { open(menu.hidden); });
	opts.forEach(function (o) {
		o.addEventListener('click', function () {
			picked = o.getAttribute('data-place');
			try { var s = stored(); s.region = picked; window.localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {}
			show(picked);
			open(false);
			btn.focus();
			document.dispatchEvent(new CustomEvent('maypiano:place', { detail: { region: picked } }));
		});
	});
	/* The menu shuts on a press anywhere else, and on Escape (which hands focus back to the flag button). */
	document.addEventListener('pointerdown', function (e) { if (!menu.hidden && !wrap.contains(e.target)) { open(false); } });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { open(false); btn.focus(); } });
	window.MayPianoPlace = { picked: function () { return picked; }, show: show };
	show(picked || 'VN');
})();
JS;
}
