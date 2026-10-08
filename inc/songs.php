<?php
/**
 * Bài hát lẻ: one song of a course sold on its own, at the page /bai-hat-le/.
 *
 * The list is data/le/bai-hat-le.json (kept out of data/*.json, which holds whole courses). Each song becomes a small
 * Tutor LMS course of its own at /courses/bai-le-<slug>/, whose lessons reuse the videos of the lessons it comes from
 * in the full course. Nothing is copied on Bunny. A buyer pays through the same order flow as a course, and approving
 * the order opens that small course only.
 *
 * A song is on sale only when every lesson it comes from is on the site and has its video.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Catalog key of a song: what an order line carries, like 'dem-hat' for a course. */
function maypiano_song_key( $slug ) {
	return 'bai-' . $slug;
}

/** Address of the small course a song becomes. */
function maypiano_song_course_slug( $slug ) {
	return 'bai-le-' . $slug;
}

/**
 * Every song in the file, keyed by slug. Each one: slug, title, from (full course address), label (full course name),
 * lessons (lesson numbers in the full course), vnd (price).
 */
function maypiano_songs() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out  = array();
	$file = get_theme_file_path( 'data/le/bai-hat-le.json' );
	$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( (array) ( $data['groups'] ?? array() ) as $group ) {
		$from  = sanitize_title( (string) ( $group['from'] ?? '' ) );
		$each  = (float) ( $group['vnd_per_video'] ?? 0 );
		$label = trim( (string) ( $group['label'] ?? '' ) );
		if ( '' === $from || $each <= 0 ) {
			continue;
		}
		foreach ( (array) ( $group['songs'] ?? array() ) as $row ) {
			$slug    = sanitize_title( (string) ( $row['slug'] ?? '' ) );
			$title   = trim( (string) ( $row['title'] ?? '' ) );
			$lessons = array_values( array_filter( array_map( 'absint', (array) ( $row['lessons'] ?? array() ) ) ) );
			if ( '' === $slug || '' === $title || ! $lessons || isset( $out[ $slug ] ) ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'    => $slug,
				'title'   => $title,
				'from'    => $from,
				'label'   => $label,
				'lessons' => $lessons,
				'vnd'     => $each * count( $lessons ),
			);
		}
	}
	return $out;
}

/** The songs as entries of the catalog, so orders, prices and opening the course work as they do for a course. */
function maypiano_song_catalog() {
	$out = array();
	foreach ( maypiano_songs() as $song ) {
		$out[ maypiano_song_key( $song['slug'] ) ] = array(
			'name'   => 'Bài lẻ: ' . $song['title'] . ( '' !== $song['label'] ? ' (' . $song['label'] . ')' : '' ),
			'vnd'    => $song['vnd'],
			'song'   => $song['slug'],
			'match'  => '',
			'course' => maypiano_song_course_slug( $song['slug'] ),
		);
	}
	return $out;
}

/** A song's price in one currency. AUD and USD follow the rates the course prices were last set from. */
function maypiano_song_price( $slug, $currency ) {
	$songs = maypiano_songs();
	if ( ! isset( $songs[ $slug ] ) ) {
		return null;
	}
	$vnd = (float) $songs[ $slug ]['vnd'];
	if ( 'VND' === $currency ) {
		return $vnd;
	}
	$base = maypiano_fx_base();
	return isset( $base[ $currency ] ) ? (float) maypiano_fx_convert( $vnd, (float) $base[ $currency ] ) : null;
}

/** The full-course lesson a song lesson shows, or 0 when it is not on the site yet. */
function maypiano_song_source( $from, $no ) {
	return maypiano_curriculum_find( 'lesson', $from . '/bai-' . (int) $no );
}

/** True when every lesson the song comes from is on the site with its video. */
function maypiano_song_ready( $song ) {
	foreach ( $song['lessons'] as $no ) {
		$src = maypiano_song_source( $song['from'], $no );
		if ( ! $src || '' === (string) get_post_meta( $src, '_maypiano_bunny', true ) ) {
			return false;
		}
	}
	return (bool) maypiano_song_course( $song['slug'] );
}

/** The small course of a song, or 0. */
function maypiano_song_course( $slug ) {
	$found = get_posts( array(
		'post_type'      => 'courses',
		'name'           => maypiano_song_course_slug( $slug ),
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	return $found ? (int) $found[0] : 0;
}

/** Songs that can be bought right now. */
function maypiano_songs_on_sale( $fresh = false ) {
	static $out = null;
	if ( null !== $out && ! $fresh ) {
		return $out;
	}
	// Checking every lesson takes many lookups, so the answer is kept for ten minutes.
	$slugs = $fresh ? false : get_transient( 'maypiano_songs_on_sale' );
	if ( ! is_array( $slugs ) ) {
		$slugs = array_keys( array_filter( maypiano_songs(), 'maypiano_song_ready' ) );
		set_transient( 'maypiano_songs_on_sale', $slugs, 10 * MINUTE_IN_SECONDS );
	}
	$out = array_intersect_key( maypiano_songs(), array_flip( $slugs ) );
	return $out;
}

/** Address of one song on the page that sells it. */
function maypiano_song_url( $slug ) {
	return home_url( '/bai-hat-le/#bai-' . $slug );
}

/** Where the songs of one full course start on the sales page. */
function maypiano_song_group_url( $from ) {
	return home_url( '/bai-hat-le/#khoa-' . $from );
}

/** Catalog key of the full course with this address, or ''. */
function maypiano_song_course_key( $from ) {
	foreach ( maypiano_catalog() as $key => $course ) {
		if ( $course['course'] === $from ) {
			return $key;
		}
	}
	return '';
}

/** For the home page and the sign-up page: each course with songs on sale, by catalog key, with where they are. */
function maypiano_song_course_links() {
	$out = array();
	foreach ( maypiano_songs_on_sale() as $song ) {
		$key = maypiano_song_course_key( $song['from'] );
		if ( '' !== $key ) {
			$out[ $key ] = maypiano_song_group_url( $song['from'] );
		}
	}
	return $out;
}

/** For the home page lesson lists: the songs on sale, in order, each with its course's catalog key. */
function maypiano_song_list() {
	$out = array();
	foreach ( maypiano_songs_on_sale() as $song ) {
		$out[] = array(
			't' => $song['title'],
			'k' => maypiano_song_course_key( $song['from'] ),
			'u' => maypiano_song_url( $song['slug'] ),
		);
	}
	return $out;
}

/** For a course page: the first lesson of each song on sale, by the lesson's hidden key, with where to buy the song. */
function maypiano_song_lesson_links() {
	$out = array();
	foreach ( maypiano_songs_on_sale() as $song ) {
		$out[ $song['from'] . '/bai-' . (int) $song['lessons'][0] ] = maypiano_song_url( $song['slug'] );
	}
	return $out;
}

/** For the home page: the songs on sale, by title in lower case, with where to buy each. */
function maypiano_song_links() {
	$out = array();
	foreach ( maypiano_songs_on_sale() as $song ) {
		$out[ mb_strtolower( $song['title'] ) ] = maypiano_song_url( $song['slug'] );
	}
	return $out;
}

/** Id of Mây's teacher account, or 0. */
function maypiano_song_teacher() {
	$email = trim( (string) get_option( 'maypiano_teacher_email', '' ) );
	$user  = '' !== $email ? get_user_by( 'email', $email ) : null;
	return $user ? (int) $user->ID : 0;
}

/**
 * Makes the small course of every song, or brings it up to date. Its lessons are named and timed after the full
 * course's lessons, from the course files in data/, and each lesson remembers which full-course lesson it shows.
 */
function maypiano_songs_build() {
	$titles = array();
	foreach ( (array) glob( get_theme_file_path( 'data/*.json' ) ) as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( empty( $data['course'] ) || empty( $data['topics'] ) ) {
			continue;
		}
		foreach ( $data['topics'] as $topic ) {
			foreach ( (array) $topic['lessons'] as $lesson ) {
				$titles[ sanitize_title( $data['course'] ) ][ (int) $lesson['no'] ] = array( (string) $lesson['title'], (string) ( $lesson['length'] ?? '' ) );
			}
		}
	}
	$teacher = maypiano_song_teacher();
	$made    = 0;
	foreach ( maypiano_songs() as $song ) {
		$slug   = maypiano_song_course_slug( $song['slug'] );
		$course = maypiano_song_course( $song['slug'] );
		$about  = 'Bài lẻ trong khóa ' . $song['label'] . '. Mây hướng dẫn trọn bài ' . $song['title'] . ( count( $song['lessons'] ) > 1 ? ' qua ' . count( $song['lessons'] ) . ' video.' : '.' );
		$post   = array(
			'post_type'    => 'courses',
			'post_name'    => $slug,
			'post_title'   => $song['title'],
			'post_excerpt' => $about,
			'post_status'  => 'publish',
		);
		if ( $teacher ) {
			$post['post_author'] = $teacher;
		}
		if ( $course ) {
			$post['ID'] = $course;
			wp_update_post( $post );
		} else {
			$post['post_content'] = '';
			$course               = (int) wp_insert_post( $post );
			if ( ! $course ) {
				continue;
			}
		}
		update_post_meta( $course, '_maypiano_song', $song['slug'] );
		// Never free: a learner gets in only through a paid order or the owner.
		update_post_meta( $course, '_tutor_course_price_type', 'paid' );
		$topic = maypiano_curriculum_put( 'topics', $slug . '/phan-1', $song['title'], $course, 1 );
		if ( ! $topic ) {
			continue;
		}
		foreach ( $song['lessons'] as $i => $no ) {
			$known = $titles[ $song['from'] ][ $no ] ?? array( $song['title'] . ( count( $song['lessons'] ) > 1 ? ', phần ' . ( $i + 1 ) : '' ), '' );
			$id    = maypiano_curriculum_put( 'lesson', $slug . '/bai-' . $no, $known[0], $topic, $i + 1 );
			if ( ! $id ) {
				continue;
			}
			update_post_meta( $id, '_tutor_course_id_for_lesson', $course );
			update_post_meta( $id, '_maypiano_song_src', $song['from'] . '/bai-' . $no );
			$video            = get_post_meta( $id, '_video', true );
			$video            = is_array( $video ) ? $video : array( 'source' => '-1' );
			$video['runtime'] = maypiano_curriculum_runtime( $known[1] );
			update_post_meta( $id, '_video', $video );
		}
		$made++;
	}
	return $made;
}

/** Builds again whenever the song list or a course file changes. Runs after the courses are built. */
add_action( 'init', function () {
	if ( ! post_type_exists( 'courses' ) || ! post_type_exists( 'lesson' ) ) {
		return;
	}
	$files = array_merge( array( get_theme_file_path( 'data/le/bai-hat-le.json' ) ), (array) glob( get_theme_file_path( 'data/*.json' ) ) );
	$stamp = MAYPIANO_VERSION;
	foreach ( $files as $file ) {
		$stamp .= '-' . ( is_readable( $file ) ? md5_file( $file ) : '0' );
	}
	$stamp = md5( $stamp );
	$seen  = (array) get_option( 'maypiano_songs_built', array() );
	if ( ( $seen['stamp'] ?? '' ) === $stamp || get_transient( 'maypiano_songs_busy' ) ) {
		return;
	}
	set_transient( 'maypiano_songs_busy', 1, 5 * MINUTE_IN_SECONDS );
	$made = maypiano_songs_build();
	update_option( 'maypiano_songs_built', array( 'stamp' => $stamp, 'at' => time(), 'made' => $made ), false );
	delete_transient( 'maypiano_songs_on_sale' );
	delete_transient( 'maypiano_songs_busy' );
}, 100 );

/** Bunny video of a lesson: its own, or for a song lesson, the one of the full-course lesson it shows. */
function maypiano_song_video_of( $lesson_id ) {
	$src = (string) get_post_meta( $lesson_id, '_maypiano_song_src', true );
	if ( '' === $src ) {
		return '';
	}
	$from = maypiano_curriculum_find( 'lesson', $src );
	return $from ? (string) get_post_meta( $from, '_maypiano_bunny', true ) : '';
}

/** The small course of a song is only for those who bought it. Anyone else is sent to the song on the sales page. */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( array( 'courses', 'lesson' ) ) ) {
		return;
	}
	$id     = get_queried_object_id();
	$course = 'lesson' === get_post_type( $id ) ? (int) get_post_meta( $id, '_tutor_course_id_for_lesson', true ) : $id;
	$song   = $course ? (string) get_post_meta( $course, '_maypiano_song', true ) : '';
	if ( '' === $song ) {
		return;
	}
	$inside = is_user_logged_in() && ( current_user_can( 'edit_post', $course ) || ( function_exists( 'tutor_utils' ) && tutor_utils()->is_enrolled( $course, get_current_user_id() ) ) );
	if ( ! $inside ) {
		wp_safe_redirect( maypiano_song_url( $song ), 302 );
		exit;
	}
}, 5 );

/** Makes the page once, so the address works as soon as the theme is deployed. */
add_action( 'init', function () {
	if ( get_option( 'maypiano_song_page' ) ) {
		return;
	}
	if ( ! get_page_by_path( 'bai-hat-le' ) ) {
		$id = wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Bài hát lẻ',
			'post_name'   => 'bai-hat-le',
		) );
		if ( ! $id || is_wp_error( $id ) ) {
			return;
		}
	}
	update_option( 'maypiano_song_page', 1 );
}, 20 );

add_action( 'rest_api_init', function () {
	/** Order for one song. Same checks as a course order; the buyer then pays on the sign-up page. */
	register_rest_route( 'maypiano/v1', '/song-order', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
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
					return array( 'url' => maypiano_order_url( $again ) );
				}
			} else {
				$token = wp_generate_uuid4();
			}
			$email = sanitize_email( (string) $req->get_param( 'email' ) );
			$name  = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
			if ( ! is_email( $email ) || '' === $name || mb_strlen( $name ) > 80 ) {
				return new WP_Error( 'invalid', 'Missing name or email', array( 'status' => 400 ) );
			}
			$slug = sanitize_title( (string) $req->get_param( 'song' ) );
			if ( ! isset( maypiano_songs_on_sale()[ $slug ] ) ) {
				return new WP_Error( 'empty', 'This song is not on sale', array( 'status' => 400 ) );
			}
			if ( maypiano_throttled( 'order', 30 ) ) {
				return new WP_Error( 'too_many', 'Too many requests', array( 'status' => 429 ) );
			}
			$region = maypiano_region( (string) $req->get_param( 'region' ) );
			$order  = maypiano_place_order( array(
				'items'  => array( maypiano_song_key( $slug ) ),
				'name'   => $name,
				'email'  => $email,
				'phone'  => '',
				'region' => $region,
				'method' => 'VN' === $region ? 'bank' : 'paypal',
				'token'  => $token,
			) );
			return is_wp_error( $order ) ? $order : array( 'url' => maypiano_order_url( $order ) );
		},
	) );
} );

/** Cài đặt > May Piano: which songs are on sale, and what each is still waiting for. */
function maypiano_song_settings_section() {
	maypiano_songs_on_sale( true );
	echo '<h2 id="mp-songs">Bài hát lẻ</h2>';
	echo '<p>Danh sách nằm trong <code>data/le/bai-hat-le.json</code>. Một bài chỉ hiện ở trang <a href="' . esc_url( home_url( '/bai-hat-le/' ) ) . '">Bài hát lẻ</a> khi mọi bài học gốc của nó đã có trên site và có video.</p>';
	echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Bài</th><th>Khóa gốc, bài số</th><th>Giá</th><th>Tình trạng</th></tr></thead><tbody>';
	foreach ( maypiano_songs() as $song ) {
		$wait = array();
		foreach ( $song['lessons'] as $no ) {
			$src = maypiano_song_source( $song['from'], $no );
			if ( ! $src ) {
				$wait[] = 'bài ' . $no . ' chưa có trên site';
			} elseif ( '' === (string) get_post_meta( $src, '_maypiano_bunny', true ) ) {
				$wait[] = 'bài ' . $no . ' chưa có video';
			}
		}
		$state = $wait ? 'Chờ: ' . implode( ', ', $wait ) : ( maypiano_song_course( $song['slug'] ) ? 'Đang bán' : 'Chờ site tạo khóa nhỏ' );
		echo '<tr><td>' . esc_html( $song['title'] ) . '</td><td>' . esc_html( $song['label'] . ', ' . implode( ' và ', $song['lessons'] ) ) . '</td><td>' . esc_html( maypiano_money( $song['vnd'], 'VND' ) ) . '</td><td>' . ( $wait ? '<strong>' . esc_html( $state ) . '</strong>' : esc_html( $state ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
}

add_action( 'wp_head', function () {
	if ( is_page( 'bai-hat-le' ) ) {
		echo '<meta name="description" content="' . esc_attr( 'Học trọn một bài hát piano cùng Mây, không cần mua cả khóa. Video hướng dẫn chi tiết từng bài.' ) . '">' . "\n";
	}
}, 2 );
