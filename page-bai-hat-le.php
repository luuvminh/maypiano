<?php
/**
 * Bài hát lẻ (the page with the address /bai-hat-le/). Each song on sale has its own small order form, like the
 * sheets page. Paying happens on the sign-up page, the same as for a course.
 */
get_header();
$mp_songs  = maypiano_songs_on_sale();
$mp_groups = array();
foreach ( $mp_songs as $mp_song ) {
	$mp_groups[ $mp_song['label'] ][] = $mp_song;
}
?>
<main class="mp-plain mp-wide mp-sheets mp-songs">
<p class="mp-kicker">Bài hát lẻ</p>
<h1>Học trọn một bài bạn thích</h1>
<p class="mp-lead">Bạn chỉ muốn đàn một bài? Mây tách riêng những bài dạy trọn vẹn từ đầu tới cuối trong khóa học. Bạn mua bài nào, Mây mở bài đó trong tài khoản học của bạn.</p>
<?php if ( $mp_songs ) : ?>
<div class="mp-sheet-list-wrap" data-api="<?php echo esc_url( rest_url( 'maypiano/v1/' ) ); ?>">
	<?php foreach ( $mp_groups as $mp_label => $mp_list ) : ?>
<h2 class="mp-song-group">Trong khóa <?php echo esc_html( $mp_label ); ?></h2>
<ul class="mp-sheet-list">
		<?php
		foreach ( $mp_list as $mp_song ) :
			$mp_id = 'mp-buy-' . $mp_song['slug'];
			$mp_n  = count( $mp_song['lessons'] );
			?>
<li id="bai-<?php echo esc_attr( $mp_song['slug'] ); ?>">
<div class="mp-sheet-text">
<h2><?php echo esc_html( $mp_song['title'] ); ?></h2>
<p class="mp-sheet-level"><?php echo esc_html( $mp_n > 1 ? $mp_n . ' video hướng dẫn' : '1 video hướng dẫn' ); ?></p>
</div>
<div class="mp-sheet-buy">
<span class="mp-sheet-price" data-vnd="<?php echo esc_attr( maypiano_money( maypiano_song_price( $mp_song['slug'], 'VND' ), 'VND' ) ); ?>" data-aud="<?php echo esc_attr( maypiano_money( maypiano_song_price( $mp_song['slug'], 'AUD' ), 'AUD' ) ); ?>" data-usd="<?php echo esc_attr( maypiano_money( maypiano_song_price( $mp_song['slug'], 'USD' ), 'USD' ) ); ?>"><?php echo esc_html( maypiano_money( maypiano_song_price( $mp_song['slug'], 'VND' ), 'VND' ) ); ?></span>
<button type="button" class="mp-sheet-get" aria-expanded="false" aria-controls="<?php echo esc_attr( $mp_id ); ?>">Mua bài này<span class="screen-reader-text"> <?php echo esc_html( $mp_song['title'] ); ?></span></button>
</div>
<form class="mp-sheet-form" id="<?php echo esc_attr( $mp_id ); ?>" hidden data-song="<?php echo esc_attr( $mp_song['slug'] ); ?>">
<p class="mp-sheet-form-lead">Nhận được tiền, Mây mở bài này và gửi cách vào học tới email của bạn.</p>
<label>Họ tên của bạn<input type="text" name="name" autocomplete="name" maxlength="80" required></label>
<label>Email để vào học<input type="email" name="email" autocomplete="email" inputmode="email" required></label>
<label class="mp-sheet-trap" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
<p class="mp-sheet-err" role="alert" hidden></p>
<button type="submit">Đặt mua và xem cách trả tiền</button>
</form>
</li>
		<?php endforeach; ?>
</ul>
	<?php endforeach; ?>
</div>
<p class="mp-sheet-how">Ở Việt Nam, bạn chuyển khoản ngân hàng. Ở nước ngoài, bạn trả qua PayPal. Muốn học nhiều bài, bạn <a href="<?php echo esc_url( home_url( '/#chon-khoa' ) ); ?>">mua cả khóa</a> sẽ lợi hơn nhiều.</p>
<script>
(function () {
	var wrap = document.querySelector('.mp-sheet-list-wrap');
	if (!wrap) return;
	var api = wrap.getAttribute('data-api');
	var region = '';
	var post = function (path, data) {
		return fetch(api + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(function (r) {
			return r.json().then(function (j) { if (!r.ok) throw j; return j; });
		});
	};
	post('config', {}).then(function (cfg) {
		region = cfg.region || '';
		var attr = region === 'AU' ? 'data-aud' : (region === 'INTL' ? 'data-usd' : 'data-vnd');
		wrap.querySelectorAll('.mp-sheet-price').forEach(function (el) { el.textContent = el.getAttribute(attr); });
	}).catch(function () {});
	var openForm = function (btn, focus) {
		var form = document.getElementById(btn.getAttribute('aria-controls'));
		var open = form.hidden;
		form.hidden = !open;
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open && focus) form.querySelector('input[name=name]').focus();
	};
	wrap.querySelectorAll('.mp-sheet-get').forEach(function (btn) {
		btn.addEventListener('click', function () { openForm(btn, true); });
	});
	/* Coming from the home page with one song picked: show that song with its form open. */
	var hash = decodeURIComponent(window.location.hash.slice(1));
	var li = hash ? document.getElementById(hash) : null;
	if (li && li.querySelector('.mp-sheet-get')) {
		li.classList.add('mp-song-picked');
		openForm(li.querySelector('.mp-sheet-get'), false);
		li.scrollIntoView({ block: 'start' });
	}
	wrap.querySelectorAll('.mp-sheet-form').forEach(function (form) {
		var fresh = function () { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2) + '-song'; };
		var token = fresh();
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = form.querySelector('button[type=submit]');
			var err = form.querySelector('.mp-sheet-err');
			var label = btn.textContent;
			err.hidden = true;
			btn.disabled = true;
			btn.textContent = 'Đang gửi đơn…';
			post('song-order', { song: form.getAttribute('data-song'), name: form.elements.name.value, email: form.elements.email.value, website: form.elements.website.value, region: region, token: token }).then(function (res) {
				token = fresh();
				btn.disabled = false;
				btn.textContent = label;
				window.location.href = res.url;
			}).catch(function (j) {
				var code = j && j.code;
				err.textContent = code === 'invalid' ? 'Bạn điền họ tên và email đúng dạng giúp Mây nhé.' : (code === 'too_many' ? 'Bạn thử lại sau ít phút nhé.' : 'Chưa gửi được đơn. Bạn thử lại, hoặc nhắn Mây qua Facebook nhé.');
				err.hidden = false;
				btn.disabled = false;
				btn.textContent = label;
			});
		});
	});
})();
</script>
<?php else : ?>
<div class="mp-sheet-empty">
<h2>Mây đang chuẩn bị những bài đầu tiên</h2>
<p>Bài hát lẻ sẽ có ở đây trong thời gian tới. Trong lúc chờ, bạn xem các khóa học của Mây nhé.</p>
<p class="mp-lost-go"><a class="mp-main" href="<?php echo esc_url( home_url( '/#chon-khoa' ) ); ?>">Xem các khóa học</a></p>
</div>
<?php endif; ?>
</main>
<?php
get_footer();
