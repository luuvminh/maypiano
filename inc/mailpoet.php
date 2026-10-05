<?php
/**
 * MailPoet (the plugin behind the mailing list), made to speak Vietnamese as Mây.
 * Covers the "please confirm your email" message, the pages a subscriber lands on,
 * and the name shown as the sender.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bump this to write the settings below into MailPoet again. */
const MAYPIANO_MAILPOET_SETUP = '1';

/**
 * MailPoet keeps the confirmation message and the sender in its own settings, filled with English
 * defaults. Written once, so later edits made in MailPoet > Settings are kept.
 */
add_action( 'wp_loaded', function () {
	if ( MAYPIANO_MAILPOET_SETUP === get_option( 'maypiano_mailpoet_setup' ) || ! class_exists( '\MailPoet\Settings\SettingsController' ) ) {
		return;
	}
	try {
		$settings = \MailPoet\Settings\SettingsController::getInstance();
		$settings->set( 'signup_confirmation.subject', 'Bạn xác nhận email để nhận bài học từ Mây Piano nhé' );
		$settings->set(
			'signup_confirmation.body',
			"Chào [subscriber:firstname | default:bạn],\n\n"
			. "Mây vừa nhận được email của bạn ở Mây Piano. Để Mây gửi bài học và ưu đãi cho bạn, bạn bấm vào dòng dưới đây để xác nhận nhé:\n\n"
			. "[activation_link]Bấm vào đây để xác nhận email của bạn.[/activation_link]\n\n"
			. "Nếu bạn không đăng ký, bạn cứ bỏ qua email này. Khi bạn chưa xác nhận, Mây sẽ không gửi thêm email nào.\n\n"
			. "Cảm ơn bạn,\nMây\n\n"
			. '<a target="_blank" href="[site:homepage_url]">Mây Piano</a>'
		);
		// The plain message above is the one sent, not MailPoet's English template.
		$settings->set( 'signup_confirmation.use_mailpoet_editor', false );
		$settings->set( 'sender.name', 'Mây Piano' );
		update_option( 'maypiano_mailpoet_setup', MAYPIANO_MAILPOET_SETUP );
	} catch ( \Throwable $e ) {
		// MailPoet not ready or changed inside: leave its settings alone and try again on a later visit.
		return;
	}
} );

/** English text of MailPoet's subscriber pages, mapped to Vietnamese in languages/mailpoet-vi.json. */
function maypiano_mailpoet_vi() {
	static $map = null;
	if ( null === $map ) {
		$map  = array();
		$file = get_theme_file_path( 'languages/mailpoet-vi.json' );
		if ( is_readable( $file ) ) {
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $data ) ) {
				$map = $data;
			}
		}
	}
	return $map;
}

/** Only where subscribers look. The admin screens keep MailPoet's own text. */
add_filter( 'gettext', function ( $translation, $text, $domain ) {
	if ( 'mailpoet' === $domain && ( ! is_admin() || wp_doing_ajax() ) ) {
		$map = maypiano_mailpoet_vi();
		if ( isset( $map[ $text ] ) ) {
			return $map[ $text ];
		}
	}
	return $translation;
}, 20, 3 );
