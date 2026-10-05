<?php
/**
 * The three newest lessons on Mây's YouTube channel, for the home page.
 * Read from the channel's public feed, kept for an hour. When YouTube cannot be read, the last good list is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MAYPIANO_YT_CHANNEL = 'UCHTx5Zck4uICJoIzeuDdq_g';

/** Up to three videos from one feed, newest first: array of array( 'id', 'title' ). Shorts are left out. */
function maypiano_videos_read( $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return array();
	}
	$out = array();
	if ( ! preg_match_all( '~<entry>(.*?)</entry>~s', (string) wp_remote_retrieve_body( $res ), $entries ) ) {
		return array();
	}
	foreach ( $entries[1] as $entry ) {
		if ( false !== strpos( $entry, '/shorts/' ) ) {
			continue;
		}
		if ( ! preg_match( '~<yt:videoId>([A-Za-z0-9_-]{11})</yt:videoId>~', $entry, $id ) || ! preg_match( '~<title>(.*?)</title>~s', $entry, $title ) ) {
			continue;
		}
		$name = trim( wp_strip_all_tags( html_entity_decode( $title[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) ) );
		if ( '' === $name ) {
			continue;
		}
		$out[] = array( 'id' => $id[1], 'title' => $name );
		if ( 3 === count( $out ) ) {
			break;
		}
	}
	return $out;
}

/** The three newest videos. Never empty: falls back to the last good list, then to the list known when this was written. */
function maypiano_videos() {
	$cached = get_transient( 'maypiano_videos' );
	if ( is_array( $cached ) && 3 === count( $cached ) ) {
		return $cached;
	}
	// The channel's "videos" list (no Shorts) first; the whole-channel feed if that one is ever withdrawn.
	$list = maypiano_videos_read( 'https://www.youtube.com/feeds/videos.xml?playlist_id=UULF' . substr( MAYPIANO_YT_CHANNEL, 2 ) );
	if ( 3 !== count( $list ) ) {
		$list = maypiano_videos_read( 'https://www.youtube.com/feeds/videos.xml?channel_id=' . MAYPIANO_YT_CHANNEL );
	}
	if ( 3 === count( $list ) ) {
		update_option( 'maypiano_videos_last', $list, false );
		set_transient( 'maypiano_videos', $list, HOUR_IN_SECONDS );
		return $list;
	}
	$last = get_option( 'maypiano_videos_last' );
	if ( ! is_array( $last ) || 3 !== count( $last ) ) {
		$last = array(
			array( 'id' => 'n0U9Vot1l3Y', 'title' => 'Chi Mai - Ennio Morricone [hướng dẫn đàn] May Piano' ),
			array( 'id' => 'ZkwbjtTlqE0', 'title' => 'Love story - Indila | hướng dẫn đàn [May Piano]' ),
			array( 'id' => '8-SIqVsGAz4', 'title' => 'Track in Time -Dennis Kuo [hướng dẫn đàn] Mây Piano' ),
		);
	}
	// YouTube did not answer: try again in ten minutes rather than on every visit.
	set_transient( 'maypiano_videos', $last, 10 * MINUTE_IN_SECONDS );
	return $last;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'maypiano/v1', '/videos', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () {
			nocache_headers();
			return array( 'videos' => maypiano_videos() );
		},
	) );
} );
