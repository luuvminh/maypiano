<?php
/**
 * Vietnamese for Tutor LMS. The plugin ships no Vietnamese, so its interface text is translated here.
 * languages/tutor-vi.json maps each English string to Vietnamese.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The translation table, read once per request. */
function maypiano_tutor_vi() {
	static $map = null;
	if ( null === $map ) {
		$map  = array();
		$file = get_theme_file_path( 'languages/tutor-vi.json' );
		if ( is_readable( $file ) ) {
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $data ) ) {
				$map = $data;
			}
		}
	}
	return $map;
}

/** Only on the visitor side of a Vietnamese site. The admin screens keep the plugin's own text. */
function maypiano_tutor_vi_on( $domain ) {
	return 0 === strpos( (string) $domain, 'tutor' ) && 0 === strpos( determine_locale(), 'vi' ) && ( ! is_admin() || wp_doing_ajax() );
}

add_filter( 'gettext', function ( $translation, $text, $domain ) {
	if ( maypiano_tutor_vi_on( $domain ) ) {
		$map = maypiano_tutor_vi();
		if ( isset( $map[ $text ] ) ) {
			return $map[ $text ];
		}
	}
	return $translation;
}, 20, 3 );

add_filter( 'gettext_with_context', function ( $translation, $text, $context, $domain ) {
	if ( maypiano_tutor_vi_on( $domain ) ) {
		$map = maypiano_tutor_vi();
		if ( isset( $map[ $text ] ) ) {
			return $map[ $text ];
		}
	}
	return $translation;
}, 20, 4 );

add_filter( 'ngettext', function ( $translation, $single, $plural, $number, $domain ) {
	if ( maypiano_tutor_vi_on( $domain ) ) {
		$map = maypiano_tutor_vi();
		$key = 1 === (int) $number ? $single : $plural;
		if ( isset( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		if ( isset( $map[ $single ] ) ) {
			return $map[ $single ];
		}
	}
	return $translation;
}, 20, 5 );

/** Text Tutor LMS prints without offering it for translation. */
add_action( 'wp_footer', function () {
	if ( ! function_exists( 'maypiano_course_subpage' ) || ! is_singular( 'courses' ) || ! maypiano_course_subpage() ) {
		return;
	}
	?>
<script>
(function () {
	var words = { 'Search questions, topics...': 'Tìm câu hỏi...' };
	function fix() {
		document.querySelectorAll('input[placeholder],textarea[placeholder]').forEach(function (el) {
			var vi = words[el.getAttribute('placeholder')];
			if (vi) { el.setAttribute('placeholder', vi); }
		});
	}
	fix();
	document.addEventListener('DOMContentLoaded', fix);
})();
</script>
	<?php
}, 99 );
