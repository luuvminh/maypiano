<?php
/**
 * Change requests by Telegram.
 *
 * In one Telegram group, a person who may ask writes to the bot what should change on the site. The bot shows the
 * request to the one person who approves, with three buttons: approve, edit then approve, or no. Only an approved
 * request is ever worked on, and the work itself is done by the site owner's tools, not by this file.
 *
 * Each request is a private entry ("Yêu cầu qua Telegram"). Its status says where it stands:
 *   pending = waiting for the approver
 *   draft   = approved, waiting to be done
 *   private = done; the entry's excerpt is the report, sent to the group once
 *   trash   = refused
 * An entry becomes "draft" only through the approver's button in Telegram. Nothing else, not the admin screens and
 * not the site's other tools, can approve a request or change what it says.
 *
 * A request may come with pictures (a screenshot of the spot to fix). The site keeps only Telegram's own codes for those
 * pictures, never the pictures themselves; whoever does the work fetches them from Telegram with the bot's token.
 *
 * The bot's token lives in the site's settings, never in this repository. Telegram's calls are accepted only with
 * the secret the site handed to Telegram when it registered the address.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_TG_TYPE     = 'mp_lenh';
const MAYPIANO_TG_HOOK_VER = '1';
const MAYPIANO_TG_MAX_OPEN = 20;
const MAYPIANO_TG_MAX_TEXT = 2000;
const MAYPIANO_TG_MAX_FILES = 6;

add_action( 'init', function () {
	register_post_type( MAYPIANO_TG_TYPE, array(
		'label'               => 'Yêu cầu qua Telegram',
		'public'              => false,
		'show_ui'             => true,
		'show_in_rest'        => true,
		'rest_base'           => MAYPIANO_TG_TYPE,
		'exclude_from_search' => true,
		'supports'            => array( 'title', 'editor', 'excerpt' ),
		'capability_type'     => 'page',
		'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'        => true,
		'menu_icon'           => 'dashicons-megaphone',
	) );
} );

/**
 * The guard. Outside this file's own steps, an entry keeps its words and may only move forward:
 * approved to done, or anything to the bin. So approving always takes the approver's button.
 */
add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
	if ( MAYPIANO_TG_TYPE !== ( $data['post_type'] ?? '' ) || ! empty( $GLOBALS['maypiano_tg_inside'] ) ) {
		return $data;
	}
	$old = ! empty( $postarr['ID'] ) ? get_post( (int) $postarr['ID'] ) : null;
	if ( ! $old || MAYPIANO_TG_TYPE !== $old->post_type ) {
		if ( 'auto-draft' !== ( $data['post_status'] ?? '' ) ) {
			$data['post_status'] = 'pending';
		}
		return $data;
	}
	$data['post_content'] = wp_slash( $old->post_content );
	$data['post_title']   = wp_slash( $old->post_title );
	$from                 = $old->post_status;
	$to                   = $data['post_status'] ?? $from;
	if ( $to !== $from && 'trash' !== $to && ! ( 'draft' === $from && 'private' === $to ) ) {
		$data['post_status'] = $from;
	}
	return $data;
}, 20, 2 );

/** Runs one of this file's own writes past the guard. */
function maypiano_tg_write( $args ) {
	$GLOBALS['maypiano_tg_inside'] = true;
	$id = empty( $args['ID'] ) ? wp_insert_post( wp_slash( $args ), true ) : wp_update_post( wp_slash( $args ), true );
	$GLOBALS['maypiano_tg_inside'] = false;
	return is_wp_error( $id ) ? 0 : (int) $id;
}

function maypiano_tg_token() {
	return trim( (string) get_option( 'maypiano_tg_token', '' ) );
}

/** The secret Telegram must send back with every call. Made once. */
function maypiano_tg_secret() {
	$secret = (string) get_option( 'maypiano_tg_secret', '' );
	if ( '' === $secret ) {
		$secret = wp_generate_password( 40, false );
		update_option( 'maypiano_tg_secret', $secret, false );
	}
	return $secret;
}

/** The code that ties one group to this site. Typed once in the group by the approver. */
function maypiano_tg_code( $fresh = false ) {
	$code = (string) get_option( 'maypiano_tg_code', '' );
	if ( $fresh || '' === $code ) {
		$code = (string) wp_rand( 10000000, 99999999 );
		update_option( 'maypiano_tg_code', $code, false );
		update_option( 'maypiano_tg_tries', 0, false );
	}
	return $code;
}

function maypiano_tg_chat() {
	return (string) get_option( 'maypiano_tg_chat', '' );
}

function maypiano_tg_boss() {
	return (string) get_option( 'maypiano_tg_boss', '' );
}

/** Who may ask, as Telegram user id => name. */
function maypiano_tg_askers() {
	$askers = get_option( 'maypiano_tg_askers', array() );
	return is_array( $askers ) ? $askers : array();
}

/** One call to Telegram. Returns the "result" part, or null when the call failed. */
function maypiano_tg_api( $method, $body = array() ) {
	$token = maypiano_tg_token();
	if ( '' === $token ) {
		return null;
	}
	$res = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/' . $method, array(
		'timeout' => 10,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( $body ),
	) );
	if ( is_wp_error( $res ) ) {
		return null;
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return is_array( $json ) && ! empty( $json['ok'] ) ? ( $json['result'] ?? true ) : null;
}

/** Says something in the group. Returns the new message's id, or 0. */
function maypiano_tg_say( $text, $reply_to = 0, $buttons = array() ) {
	$body = array(
		'chat_id'                  => maypiano_tg_chat(),
		'text'                     => maypiano_tg_cut( $text, 3800 ),
		'disable_web_page_preview' => true,
	);
	if ( $reply_to ) {
		$body['reply_parameters'] = array( 'message_id' => (int) $reply_to, 'allow_sending_without_reply' => true );
	}
	if ( $buttons ) {
		$body['reply_markup'] = array( 'inline_keyboard' => $buttons );
	}
	$sent = maypiano_tg_api( 'sendMessage', $body );
	return is_array( $sent ) ? (int) ( $sent['message_id'] ?? 0 ) : 0;
}

/** Rewrites one of the bot's own messages and takes its buttons away. */
function maypiano_tg_rewrite( $message_id, $text ) {
	if ( $message_id ) {
		maypiano_tg_api( 'editMessageText', array(
			'chat_id'                  => maypiano_tg_chat(),
			'message_id'               => (int) $message_id,
			'text'                     => maypiano_tg_cut( $text, 3800 ),
			'disable_web_page_preview' => true,
		) );
	}
}

function maypiano_tg_cut( $text, $max ) {
	$text = trim( (string) $text );
	return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
}

/** A person's name as Telegram gives it. */
function maypiano_tg_name( $from ) {
	$name = trim( ( $from['first_name'] ?? '' ) . ' ' . ( $from['last_name'] ?? '' ) );
	return '' === $name ? 'Người dùng ' . (int) ( $from['id'] ?? 0 ) : maypiano_tg_cut( $name, 60 );
}

function maypiano_tg_now() {
	return wp_date( 'H:i d/m/Y' );
}

/** How many requests are waiting or approved but not done. */
function maypiano_tg_open() {
	$counts = wp_count_posts( MAYPIANO_TG_TYPE );
	return (int) ( $counts->pending ?? 0 ) + (int) ( $counts->draft ?? 0 );
}

/** The words of an entry, built from what is stored with it. */
function maypiano_tg_body( $id ) {
	$lines = array(
		'Mã yêu cầu: ' . (int) $id,
		'Người yêu cầu: ' . get_post_meta( $id, '_mp_tg_asker', true ),
		'Lúc: ' . get_post_meta( $id, '_mp_tg_at', true ),
	);
	$about = (int) get_post_meta( $id, '_mp_tg_about', true );
	if ( $about ) {
		$lines[] = 'Nói tiếp về yêu cầu trước: #' . $about;
	}
	$lines[] = '';
	$lines[] = 'Yêu cầu:';
	$lines[] = (string) get_post_meta( $id, '_mp_tg_text', true );
	$files   = maypiano_tg_files_of( $id );
	if ( $files ) {
		$lines[] = '';
		$lines[] = 'Ảnh kèm (mã ảnh của Telegram, mỗi dòng một ảnh):';
		foreach ( $files as $file ) {
			$lines[] = $file;
		}
	}
	$edit    = (string) get_post_meta( $id, '_mp_tg_edit_text', true );
	if ( '' !== $edit ) {
		$lines[] = '';
		$lines[] = 'Người duyệt đã chỉnh lại. Làm theo phần này:';
		$lines[] = $edit;
	}
	$ok = (string) get_post_meta( $id, '_mp_tg_ok', true );
	if ( '' !== $ok ) {
		$lines[] = '';
		$lines[] = 'Đã duyệt: ' . $ok;
	}
	return implode( "\n", array_map( 'esc_html', $lines ) );
}

/** Telegram's codes for the pictures in one message: a photo (its largest size) or an image sent as a file. */
function maypiano_tg_pictures( $msg ) {
	$found = array();
	if ( ! empty( $msg['photo'] ) && is_array( $msg['photo'] ) ) {
		$largest = end( $msg['photo'] );
		$found[] = is_array( $largest ) ? (string) ( $largest['file_id'] ?? '' ) : '';
	}
	if ( ! empty( $msg['document']['file_id'] ) && 0 === strpos( (string) ( $msg['document']['mime_type'] ?? '' ), 'image/' ) ) {
		$found[] = (string) $msg['document']['file_id'];
	}
	return array_values( array_filter( array_map( function ( $code ) {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $code );
	}, $found ) ) );
}

function maypiano_tg_files_of( $id ) {
	$files = get_post_meta( $id, '_mp_tg_files', true );
	return is_array( $files ) ? $files : array();
}

/** Adds pictures to a request that is not done yet. */
function maypiano_tg_add_files( $id, $files ) {
	if ( ! $files || ! in_array( get_post_status( $id ), array( 'pending', 'draft' ), true ) ) {
		return;
	}
	$all = array_slice( array_values( array_unique( array_merge( maypiano_tg_files_of( $id ), $files ) ) ), 0, MAYPIANO_TG_MAX_FILES );
	update_post_meta( $id, '_mp_tg_files', $all );
	maypiano_tg_write( array( 'ID' => $id, 'post_content' => maypiano_tg_body( $id ) ) );
}

/** Makes the entry for a new request. $approved: the approver wrote it, so it needs no second yes. */
function maypiano_tg_make( $text, $from, $message_id, $about = 0, $approved = false, $files = array(), $album = '' ) {
	$text = maypiano_tg_cut( $text, MAYPIANO_TG_MAX_TEXT );
	$name = maypiano_tg_name( $from );
	$id   = maypiano_tg_write( array(
		'post_type'    => MAYPIANO_TG_TYPE,
		'post_status'  => 'pending',
		'post_title'   => wp_html_excerpt( $name . ': ' . $text, 90, '…' ),
		'post_content' => '',
	) );
	if ( ! $id ) {
		return 0;
	}
	update_post_meta( $id, '_mp_tg_text', wp_slash( $text ) );
	update_post_meta( $id, '_mp_tg_asker', wp_slash( $name ) );
	update_post_meta( $id, '_mp_tg_at', maypiano_tg_now() );
	update_post_meta( $id, '_mp_tg_src', (int) $message_id );
	update_post_meta( $id, '_mp_tg_uid', (string) ( $from['id'] ?? '' ) );
	if ( '' !== $album ) {
		update_post_meta( $id, '_mp_tg_album', $album );
		$early = get_transient( 'mp_tg_al_' . md5( $album ) ); // Pictures of the same album that arrived first.
		$files = array_merge( $files, is_array( $early ) ? $early : array() );
	}
	if ( $files ) {
		update_post_meta( $id, '_mp_tg_files', array_slice( array_values( array_unique( $files ) ), 0, MAYPIANO_TG_MAX_FILES ) );
	}
	if ( $about ) {
		update_post_meta( $id, '_mp_tg_about', (int) $about );
	}
	if ( $approved ) {
		update_post_meta( $id, '_mp_tg_ok', wp_slash( maypiano_tg_now() . ', do chính người duyệt viết' ) );
	}
	maypiano_tg_write( array( 'ID' => $id, 'post_content' => maypiano_tg_body( $id ), 'post_status' => $approved ? 'draft' : 'pending' ) );
	return $id;
}

/** Has this Telegram message already become an entry? Telegram repeats a call it thinks was lost. */
function maypiano_tg_seen( $message_id ) {
	$found = get_posts( array(
		'post_type'   => MAYPIANO_TG_TYPE,
		'post_status' => 'any',
		'meta_key'    => '_mp_tg_src', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => (int) $message_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		'fields'      => 'ids',
		'numberposts' => 1,
	) );
	return ! empty( $found );
}

/** The entry one of the bot's messages belongs to: $key is the kind of message (card, edit prompt, report). */
function maypiano_tg_entry_by( $key, $message_id ) {
	$found = get_posts( array(
		'post_type'   => MAYPIANO_TG_TYPE,
		'post_status' => array( 'pending', 'draft', 'private' ),
		'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'  => (int) $message_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		'fields'      => 'ids',
		'numberposts' => 1,
	) );
	return $found ? (int) $found[0] : 0;
}

function maypiano_tg_card_text( $id ) {
	return 'Yêu cầu #' . (int) $id . ' của ' . get_post_meta( $id, '_mp_tg_asker', true ) . ( maypiano_tg_files_of( $id ) ? ' (có kèm ảnh)' : '' ) . ":\n\n" . get_post_meta( $id, '_mp_tg_text', true );
}

/** Shows a new request to the approver. */
function maypiano_tg_show( $id, $reply_to ) {
	$card = maypiano_tg_say(
		maypiano_tg_card_text( $id ) . "\n\nChờ duyệt.",
		$reply_to,
		array( array(
			array( 'text' => 'Duyệt', 'callback_data' => 'ok:' . $id ),
			array( 'text' => 'Sửa rồi duyệt', 'callback_data' => 'ed:' . $id ),
			array( 'text' => 'Không', 'callback_data' => 'no:' . $id ),
		) )
	);
	if ( $card ) {
		update_post_meta( $id, '_mp_tg_card', $card );
	}
}

/** Approves a waiting request. $edit: the approver's own wording, when given. */
function maypiano_tg_approve( $id, $edit = '' ) {
	$post = get_post( $id );
	if ( ! $post || MAYPIANO_TG_TYPE !== $post->post_type || 'pending' !== $post->post_status ) {
		return false;
	}
	if ( '' !== $edit ) {
		update_post_meta( $id, '_mp_tg_edit_text', wp_slash( maypiano_tg_cut( $edit, MAYPIANO_TG_MAX_TEXT ) ) );
	}
	update_post_meta( $id, '_mp_tg_ok', maypiano_tg_now() );
	maypiano_tg_write( array( 'ID' => $id, 'post_content' => maypiano_tg_body( $id ), 'post_status' => 'draft' ) );
	$text = maypiano_tg_card_text( $id );
	if ( '' !== $edit ) {
		$text .= "\n\nĐã chỉnh lại thành:\n" . get_post_meta( $id, '_mp_tg_edit_text', true );
	}
	maypiano_tg_rewrite( (int) get_post_meta( $id, '_mp_tg_card', true ), $text . "\n\nĐã duyệt lúc " . maypiano_tg_now() . '. Em sẽ làm trong lượt chạy tới và báo lại ở đây.' );
	return true;
}

/** Telegram's calls arrive here. */
add_action( 'rest_api_init', function () {
	register_rest_route( 'maypiano/v1', '/tg', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $req ) {
			$given = (string) $req->get_header( 'x-telegram-bot-api-secret-token' );
			return '' !== maypiano_tg_token() && '' !== $given && hash_equals( maypiano_tg_secret(), $given );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			nocache_headers();
			$update = $req->get_json_params();
			if ( is_array( $update ) && ! empty( $update['update_id'] ) ) {
				$lock = 'mp_tg_u_' . (int) $update['update_id'];
				if ( ! get_transient( $lock ) ) {
					set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
					if ( ! empty( $update['callback_query'] ) ) {
						maypiano_tg_on_button( $update['callback_query'] );
					} elseif ( ! empty( $update['message'] ) ) {
						maypiano_tg_on_message( $update['message'] );
					}
				}
			}
			return array( 'ok' => true );
		},
	) );
} );

/** A message's text without the bot's own name in it. Returns array( text, was the bot named ). */
function maypiano_tg_text( $msg ) {
	$text  = (string) ( $msg['text'] ?? '' );
	$text  = '' === $text ? (string) ( $msg['caption'] ?? '' ) : $text;
	$bot   = (string) get_option( 'maypiano_tg_bot', '' );
	$named = false;
	if ( '' !== $bot && false !== stripos( $text, '@' . $bot ) ) {
		$named = true;
		$text  = str_ireplace( '@' . $bot, '', $text );
	}
	return array( trim( preg_replace( '/[ \t]+/u', ' ', $text ) ), $named );
}

function maypiano_tg_on_message( $msg ) {
	$chat = $msg['chat'] ?? array();
	$from = $msg['from'] ?? array();
	if ( empty( $from['id'] ) || ! empty( $from['is_bot'] ) || ! in_array( $chat['type'] ?? '', array( 'group', 'supergroup' ), true ) ) {
		return;
	}
	list( $text, $named ) = maypiano_tg_text( $msg );
	$uid                  = (string) $from['id'];
	$mid                  = (int) ( $msg['message_id'] ?? 0 );

	// Not tied to a group yet: only the code does anything.
	if ( '' === maypiano_tg_chat() ) {
		if ( preg_match( '/^\/ghep\s+(\d{8})$/u', $text, $m ) ) {
			if ( hash_equals( maypiano_tg_code(), $m[1] ) ) {
				update_option( 'maypiano_tg_chat', (string) $chat['id'], false );
				update_option( 'maypiano_tg_boss', $uid, false );
				update_option( 'maypiano_tg_boss_name', maypiano_tg_name( $from ), false );
				update_option( 'maypiano_tg_group', maypiano_tg_cut( (string) ( $chat['title'] ?? '' ), 80 ), false );
				maypiano_tg_code( true );
				maypiano_tg_say( 'Đã ghép nhóm này với site May Piano. Người duyệt: ' . maypiano_tg_name( $from ) . ".\n\nAi muốn nhờ em sửa site thì nhắn kèm tên em (@" . get_option( 'maypiano_tg_bot', '' ) . '). Lần đầu một người nhắn, em sẽ hỏi người duyệt có cho người đó ra yêu cầu không.', $mid );
			} else {
				$tries = (int) get_option( 'maypiano_tg_tries', 0 ) + 1;
				update_option( 'maypiano_tg_tries', $tries, false );
				if ( $tries >= 5 ) {
					maypiano_tg_code( true );
				}
			}
		}
		return;
	}
	if ( (string) $chat['id'] !== maypiano_tg_chat() ) {
		return;
	}

	$reply    = $msg['reply_to_message'] ?? array();
	$reply_id = (int) ( $reply['message_id'] ?? 0 );
	$to_bot   = $reply_id && ! empty( $reply['from']['is_bot'] ) && ( $reply['from']['username'] ?? '' ) === get_option( 'maypiano_tg_bot', '' );
	$is_boss  = $uid === maypiano_tg_boss();

	// The approver answering "edit then approve".
	if ( $is_boss && $to_bot ) {
		$id = maypiano_tg_entry_by( '_mp_tg_edit', $reply_id );
		if ( $id && 'pending' === get_post_status( $id ) ) {
			if ( '' === $text ) {
				maypiano_tg_say( 'Em chỉ đọc được chữ. Anh chị viết nội dung đã chỉnh bằng chữ giúp em.', $mid );
			} elseif ( maypiano_tg_approve( $id, $text ) ) {
				maypiano_tg_say( 'Đã nhận bản chỉnh và đã duyệt yêu cầu #' . $id . '.', $mid );
			}
			return;
		}
	}

	if ( preg_match( '/^\/hangcho\b/u', $text ) ) {
		maypiano_tg_say( maypiano_tg_queue_text(), $mid );
		return;
	}

	// More pictures of an album: they belong to the request made from the album's first picture.
	$album = preg_replace( '/[^0-9A-Za-z_-]/', '', (string) ( $msg['media_group_id'] ?? '' ) );
	$own   = maypiano_tg_pictures( $msg );
	if ( '' !== $album && $own && ! $named && ( $is_boss || isset( maypiano_tg_askers()[ $uid ] ) ) && ! preg_match( '/^\//u', $text ) ) {
		$found = get_posts( array(
			'post_type'   => MAYPIANO_TG_TYPE,
			'post_status' => array( 'pending', 'draft' ),
			'meta_key'    => '_mp_tg_album', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => $album, // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'      => 'ids',
			'numberposts' => 1,
		) );
		if ( $found && (string) get_post_meta( $found[0], '_mp_tg_uid', true ) === $uid ) {
			maypiano_tg_add_files( (int) $found[0], $own );
		} elseif ( ! $found ) {
			$key   = 'mp_tg_al_' . md5( $album );
			$early = get_transient( $key );
			set_transient( $key, array_slice( array_merge( is_array( $early ) ? $early : array(), $own ), 0, MAYPIANO_TG_MAX_FILES ), 10 * MINUTE_IN_SECONDS );
		}
		return;
	}

	// A message is a request only when it is plainly meant for the bot.
	$command = (bool) preg_match( '/^\/(lam|yeucau)\b\s*/u', $text, $m );
	if ( $command ) {
		$text = trim( mb_substr( $text, mb_strlen( $m[0] ) ) );
	}
	// The approver's replies to the bot are ordinary talk unless the bot is named: what the approver asks is done without a second yes.
	if ( ! $command && ! $named && ! ( $to_bot && ! $is_boss ) ) {
		return;
	}
	if ( ! $is_boss && ! isset( maypiano_tg_askers()[ $uid ] ) ) {
		maypiano_tg_ask_about( $from, $text, $mid );
		return;
	}
	if ( '' === $text ) {
		if ( $own ) {
			$say = 'Em nhận được ảnh rồi, nhưng chưa biết cần sửa gì. Anh chị gửi lại ảnh kèm vài chữ, hoặc trả lời (reply) vào ảnh, có gọi tên em.';
		} elseif ( ! empty( $msg['voice'] ) || ! empty( $msg['video'] ) || ! empty( $msg['audio'] ) || ! empty( $msg['document'] ) || ! empty( $msg['video_note'] ) ) {
			$say = 'Em đọc được chữ và xem được ảnh, chưa nghe được ghi âm hay xem được video, file. Anh chị viết bằng chữ hoặc gửi ảnh chụp màn hình giúp em.';
		} else {
			$say = 'Anh chị viết giúp em cần sửa gì trên site.';
		}
		maypiano_tg_say( $say, $mid );
		return;
	}
	if ( maypiano_tg_seen( $mid ) ) {
		return;
	}
	if ( maypiano_tg_open() >= MAYPIANO_TG_MAX_OPEN ) {
		maypiano_tg_say( 'Hàng chờ đang đầy (' . MAYPIANO_TG_MAX_OPEN . ' yêu cầu chưa xong). Em chưa nhận thêm được.', $mid );
		return;
	}
	// Pictures: this message's own, and the one it replies to (a screenshot sent first, the words after).
	$files = $own;
	if ( $reply && ! $to_bot ) {
		$files = array_merge( $files, maypiano_tg_pictures( $reply ) );
	}
	if ( ! $files && ( ! empty( $msg['voice'] ) || ! empty( $msg['video'] ) || ! empty( $msg['document'] ) ) ) {
		$text .= "\n\n(Tin nhắn có kèm ghi âm, video hoặc file. Em không xem được phần đó.)";
	}
	$about = $to_bot ? maypiano_tg_entry_by( '_mp_tg_report', $reply_id ) : 0;
	$id    = maypiano_tg_make( $text, $from, $mid, $about, $is_boss, $files, $album );
	if ( ! $id ) {
		maypiano_tg_say( 'Em chưa ghi được yêu cầu này. Anh chị nhắn lại giúp em.', $mid );
	} elseif ( $is_boss ) {
		maypiano_tg_say( 'Đã nhận yêu cầu #' . $id . '. Người duyệt tự viết nên không cần duyệt lại. Em sẽ làm trong lượt chạy tới và báo lại ở đây.', $mid );
	} else {
		maypiano_tg_show( $id, $mid );
	}
}

/** Someone new wrote to the bot: the approver decides whether that person may ask. Their first message is kept meanwhile. */
function maypiano_tg_ask_about( $from, $text, $message_id ) {
	$uid = (string) $from['id'];
	$key = 'mp_tg_new_' . $uid;
	if ( false !== get_transient( $key ) ) {
		return; // Already asked, or refused not long ago.
	}
	set_transient( $key, array( 'name' => maypiano_tg_name( $from ), 'text' => maypiano_tg_cut( $text, MAYPIANO_TG_MAX_TEXT ), 'mid' => (int) $message_id ), WEEK_IN_SECONDS );
	maypiano_tg_say(
		'Cho ' . maypiano_tg_name( $from ) . ' ra yêu cầu sửa site trong nhóm này không? Chỉ người duyệt bấm được.',
		$message_id,
		array( array(
			array( 'text' => 'Cho phép', 'callback_data' => 'ask:ok:' . $uid ),
			array( 'text' => 'Không', 'callback_data' => 'ask:no:' . $uid ),
		) )
	);
}

function maypiano_tg_queue_text() {
	$posts = get_posts( array(
		'post_type'   => MAYPIANO_TG_TYPE,
		'post_status' => array( 'pending', 'draft' ),
		'numberposts' => MAYPIANO_TG_MAX_OPEN,
		'orderby'     => 'ID',
		'order'       => 'ASC',
	) );
	if ( ! $posts ) {
		return 'Hàng chờ trống. Không có yêu cầu nào đang chờ.';
	}
	$lines = array( 'Hàng chờ:' );
	foreach ( $posts as $post ) {
		$lines[] = '#' . $post->ID . ' (' . ( 'pending' === $post->post_status ? 'chờ duyệt' : 'đã duyệt, chờ làm' ) . '): ' . maypiano_tg_cut( (string) get_post_meta( $post->ID, '_mp_tg_text', true ), 80 );
	}
	return implode( "\n", $lines );
}

function maypiano_tg_on_button( $query ) {
	$from    = $query['from'] ?? array();
	$message = $query['message'] ?? array();
	$data    = (string) ( $query['data'] ?? '' );
	$answer  = function ( $text, $alert = false ) use ( $query ) {
		maypiano_tg_api( 'answerCallbackQuery', array( 'callback_query_id' => (string) ( $query['id'] ?? '' ), 'text' => $text, 'show_alert' => $alert ) );
	};
	if ( '' === maypiano_tg_chat() || (string) ( $message['chat']['id'] ?? '' ) !== maypiano_tg_chat() ) {
		return;
	}
	if ( (string) ( $from['id'] ?? '' ) !== maypiano_tg_boss() ) {
		$answer( 'Chỉ người duyệt bấm được nút này.', true );
		return;
	}
	$card = (int) ( $message['message_id'] ?? 0 );

	if ( preg_match( '/^ask:(ok|no):(\d+)$/', $data, $m ) ) {
		$key  = 'mp_tg_new_' . $m[2];
		$kept = get_transient( $key );
		$name = is_array( $kept ) ? (string) $kept['name'] : 'Người này';
		if ( 'no' === $m[1] ) {
			set_transient( $key, 'no', 30 * DAY_IN_SECONDS );
			maypiano_tg_rewrite( $card, $name . ' không được ra yêu cầu trong nhóm này.' );
			$answer( 'Đã ghi.' );
			return;
		}
		$askers          = maypiano_tg_askers();
		$askers[ $m[2] ] = $name;
		update_option( 'maypiano_tg_askers', $askers, false );
		delete_transient( $key );
		maypiano_tg_rewrite( $card, $name . ' được ra yêu cầu trong nhóm này.' );
		$answer( 'Đã cho phép.' );
		if ( is_array( $kept ) && '' !== $kept['text'] && ! maypiano_tg_seen( $kept['mid'] ) && maypiano_tg_open() < MAYPIANO_TG_MAX_OPEN ) {
			$id = maypiano_tg_make( $kept['text'], array( 'id' => (int) $m[2], 'first_name' => $name ), $kept['mid'] );
			if ( $id ) {
				maypiano_tg_show( $id, $kept['mid'] );
			}
		}
		return;
	}

	if ( ! preg_match( '/^(ok|ed|no):(\d+)$/', $data, $m ) ) {
		return;
	}
	$id   = (int) $m[2];
	$post = get_post( $id );
	if ( ! $post || MAYPIANO_TG_TYPE !== $post->post_type || 'pending' !== $post->post_status ) {
		$answer( 'Yêu cầu này đã được xử lý rồi.', true );
		return;
	}
	if ( 'ok' === $m[1] ) {
		maypiano_tg_approve( $id );
		$answer( 'Đã duyệt.' );
	} elseif ( 'no' === $m[1] ) {
		$GLOBALS['maypiano_tg_inside'] = true;
		wp_trash_post( $id );
		$GLOBALS['maypiano_tg_inside'] = false;
		maypiano_tg_rewrite( $card, maypiano_tg_card_text( $id ) . "\n\nKhông duyệt. Em không làm yêu cầu này." );
		$answer( 'Đã từ chối.' );
	} else {
		$prompt = maypiano_tg_say( 'Sửa yêu cầu #' . $id . ': anh chị trả lời (reply) đúng tin này bằng nội dung đã chỉnh. Em sẽ làm theo bản đó và coi như đã duyệt.', $card );
		if ( $prompt ) {
			update_post_meta( $id, '_mp_tg_edit', $prompt );
		}
		$answer( 'Trả lời tin em vừa gửi bằng nội dung đã chỉnh.' );
	}
}

/** A request marked done with a report in its excerpt: the report is sent to the group, once. */
add_action( 'save_post_' . MAYPIANO_TG_TYPE, function ( $id, $post ) {
	if ( 'private' !== $post->post_status || get_post_meta( $id, '_mp_tg_done', true ) || '' === maypiano_tg_chat() ) {
		return;
	}
	$report = trim( (string) $post->post_excerpt );
	if ( '' === $report ) {
		return;
	}
	update_post_meta( $id, '_mp_tg_done', time() );
	$sent = maypiano_tg_say( 'Yêu cầu #' . (int) $id . ":\n\n" . $report, (int) get_post_meta( $id, '_mp_tg_src', true ) );
	if ( $sent ) {
		update_post_meta( $id, '_mp_tg_report', $sent );
	} else {
		delete_post_meta( $id, '_mp_tg_done' ); // Not sent: the next save tries again.
	}
}, 10, 2 );

/*
 * Settings.
 */

add_action( 'admin_init', function () {
	register_setting( 'maypiano', 'maypiano_tg_token', array(
		'sanitize_callback' => function ( $value ) {
			$value = trim( sanitize_text_field( (string) $value ) );
			return '' === $value ? (string) get_option( 'maypiano_tg_token', '' ) : $value;
		},
	) );
	// Two switches that act when ticked and never stay stored.
	register_setting( 'maypiano', 'maypiano_tg_unpair', array(
		'sanitize_callback' => function ( $value ) {
			if ( $value ) {
				foreach ( array( 'maypiano_tg_chat', 'maypiano_tg_boss', 'maypiano_tg_boss_name', 'maypiano_tg_group', 'maypiano_tg_askers' ) as $key ) {
					delete_option( $key );
				}
				maypiano_tg_code( true );
			}
			return '';
		},
	) );
	register_setting( 'maypiano', 'maypiano_tg_off', array(
		'sanitize_callback' => function ( $value ) {
			if ( $value ) {
				maypiano_tg_api( 'deleteWebhook', array( 'drop_pending_updates' => true ) );
				foreach ( array( 'maypiano_tg_token', 'maypiano_tg_hooked', 'maypiano_tg_bot' ) as $key ) {
					delete_option( $key );
				}
			}
			return '';
		},
	) );
} );

/** Tells Telegram where to send the group's messages. Returns a line for the settings page. */
function maypiano_tg_setup() {
	if ( '' === maypiano_tg_token() ) {
		return 'Chưa có token của bot.';
	}
	$url  = rest_url( 'maypiano/v1/tg' );
	$want = md5( maypiano_tg_token() . '|' . $url . '|' . MAYPIANO_TG_HOOK_VER );
	if ( $want === get_option( 'maypiano_tg_hooked' ) ) {
		return 'Bot @' . get_option( 'maypiano_tg_bot', '' ) . ' đang nối với site.';
	}
	$me = maypiano_tg_api( 'getMe' );
	if ( ! is_array( $me ) || empty( $me['username'] ) ) {
		return 'Telegram không nhận token này. Kiểm tra lại token rồi lưu lần nữa.';
	}
	update_option( 'maypiano_tg_bot', (string) $me['username'], false );
	$set = maypiano_tg_api( 'setWebhook', array(
		'url'                  => $url,
		'secret_token'         => maypiano_tg_secret(),
		'allowed_updates'      => array( 'message', 'callback_query' ),
		'drop_pending_updates' => true,
	) );
	if ( null === $set ) {
		return 'Chưa nối được bot với site. Mở lại trang này để thử lại.';
	}
	update_option( 'maypiano_tg_hooked', $want, false );
	return 'Bot @' . $me['username'] . ' vừa được nối với site.';
}

function maypiano_tg_settings_section() {
	echo '<h2>Nhận yêu cầu sửa site qua Telegram</h2>';
	echo '<p><strong>' . esc_html( maypiano_tg_setup() ) . '</strong></p>';
	$saved = '' !== maypiano_tg_token();
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="maypiano_tg_token">Token của bot</label></th><td>';
	echo '<input type="password" autocomplete="off" class="regular-text" id="maypiano_tg_token" name="maypiano_tg_token" value="" placeholder="' . esc_attr( $saved ? 'Đã lưu' : 'Chưa có' ) . '">';
	echo '<p class="description">Lấy ở @BotFather trong Telegram. Đã lưu thì để trống là giữ nguyên. Site không hiện lại token.</p></td></tr>';
	if ( $saved ) {
		echo '<tr><th scope="row">Nhóm</th><td>';
		if ( '' === maypiano_tg_chat() ) {
			echo '<p>Chưa ghép nhóm. Thêm bot vào nhóm, rồi người duyệt gõ đúng dòng này trong nhóm:</p>';
			echo '<p><code>/ghep@' . esc_html( (string) get_option( 'maypiano_tg_bot', '' ) ) . ' ' . esc_html( maypiano_tg_code() ) . '</code></p>';
			echo '<p class="description">Người gõ dòng này trở thành người duyệt duy nhất. Mã chỉ dùng một lần. Đừng gửi mã ra ngoài.</p>';
		} else {
			echo '<p>Nhóm: <strong>' . esc_html( (string) get_option( 'maypiano_tg_group', '' ) ) . '</strong>. Người duyệt: <strong>' . esc_html( (string) get_option( 'maypiano_tg_boss_name', '' ) ) . '</strong>.</p>';
			$askers = maypiano_tg_askers();
			echo '<p>Người được ra yêu cầu: ' . ( $askers ? esc_html( implode( ', ', $askers ) ) : 'chưa có ai' ) . '.</p>';
			echo '<p>Đang chờ: ' . (int) maypiano_tg_open() . ' yêu cầu. Xem ở mục <a href="' . esc_url( admin_url( 'edit.php?post_type=' . MAYPIANO_TG_TYPE ) ) . '">Yêu cầu qua Telegram</a>.</p>';
			echo '<p><label><input type="checkbox" name="maypiano_tg_unpair" value="1"> Bỏ ghép nhóm này (xóa người duyệt và danh sách người được ra yêu cầu, rồi ghép lại từ đầu)</label></p>';
		}
		echo '<p><label><input type="checkbox" name="maypiano_tg_off" value="1"> Tắt hẳn bot (xóa token khỏi site)</label></p>';
		echo '</td></tr>';
	}
	echo '</table>';
}
