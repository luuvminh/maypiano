<?php
/**
 * Sheet nhạc (the page with the address /sheet-nhac/). Each sheet on sale has its own small order form.
 */
get_header();
$sheets = maypiano_sheets_on_sale();
$kinds  = maypiano_sheet_kinds();
?>
<main class="mp-plain mp-wide mp-sheets">
<p class="mp-kicker">Sheet nhạc</p>
<h1>Sheet nhạc Mây viết tay</h1>
<p class="mp-lead">Đây là những bản sheet Mây tự viết cho học trò, có hợp âm và gạch nhịp sẵn. Bạn mua bài nào, Mây gửi file PDF của bài đó qua email để bạn in ra hoặc mở trên máy.</p>
<?php if ( $sheets ) : ?>
<ul class="mp-sheet-list" data-api="<?php echo esc_url( rest_url( 'maypiano/v1/' ) ); ?>">
	<?php
	foreach ( $sheets as $sheet ) :
		$kind = $kinds[ $sheet['kind'] ];
		$id   = 'mp-buy-' . $sheet['slug'];
		?>
<li>
<div class="mp-sheet-text">
<h2><?php echo esc_html( $sheet['title'] ); ?></h2>
<p class="mp-sheet-level"><?php echo esc_html( $kind['label'] . ' · ' . $kind['about'] . ( $sheet['pages'] ? ' · ' . $sheet['pages'] . ' trang' : '' ) ); ?></p>
		<?php if ( '' !== $sheet['note'] ) : ?>
<p><?php echo esc_html( $sheet['note'] ); ?></p>
		<?php endif; ?>
</div>
<div class="mp-sheet-buy">
<span class="mp-sheet-price" data-vnd="<?php echo esc_attr( maypiano_money( $kind['VND'], 'VND' ) ); ?>" data-aud="<?php echo esc_attr( maypiano_money( $kind['AUD'], 'AUD' ) ); ?>" data-usd="<?php echo esc_attr( maypiano_money( $kind['USD'], 'USD' ) ); ?>"><?php echo esc_html( maypiano_money( $kind['VND'], 'VND' ) ); ?></span>
<button type="button" class="mp-sheet-get" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>">Mua sheet này<span class="screen-reader-text"> <?php echo esc_html( $sheet['title'] ); ?></span></button>
</div>
<form class="mp-sheet-form" id="<?php echo esc_attr( $id ); ?>" hidden data-sheet="<?php echo esc_attr( $sheet['slug'] ); ?>">
<p class="mp-sheet-form-lead">Mây gửi file tới email của bạn sau khi nhận được tiền.</p>
<label>Họ tên của bạn<input type="text" name="name" autocomplete="name" maxlength="80" required></label>
<label>Email nhận sheet<input type="email" name="email" autocomplete="email" inputmode="email" required></label>
<label class="mp-sheet-trap" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
<p class="mp-sheet-err" role="alert" hidden></p>
<button type="submit">Đặt mua và xem cách trả tiền</button>
</form>
</li>
	<?php endforeach; ?>
</ul>
<p class="mp-sheet-how">Ở Việt Nam, bạn chuyển khoản ngân hàng. Ở nước ngoài, bạn trả qua PayPal.</p>
<script>
(function () {
	var list = document.querySelector('.mp-sheet-list');
	if (!list) return;
	var api = list.getAttribute('data-api');
	var region = '';
	var post = function (path, data) {
		return fetch(api + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(function (r) {
			return r.json().then(function (j) { if (!r.ok) throw j; return j; });
		});
	};
	post('config', {}).then(function (cfg) {
		region = cfg.region || '';
		var attr = region === 'AU' ? 'data-aud' : (region === 'INTL' ? 'data-usd' : 'data-vnd');
		list.querySelectorAll('.mp-sheet-price').forEach(function (el) { el.textContent = el.getAttribute(attr); });
	}).catch(function () {});
	list.querySelectorAll('.mp-sheet-get').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var form = document.getElementById(btn.getAttribute('aria-controls'));
			var open = form.hidden;
			form.hidden = !open;
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) form.querySelector('input[name=name]').focus();
		});
	});
	list.querySelectorAll('.mp-sheet-form').forEach(function (form) {
		var fresh = function () { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : String(Date.now()) + '-' + Math.random().toString(36).slice(2) + '-sheet'; };
		var token = fresh();
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = form.querySelector('button[type=submit]');
			var err = form.querySelector('.mp-sheet-err');
			var label = btn.textContent;
			err.hidden = true;
			btn.disabled = true;
			btn.textContent = 'Đang gửi đơn…';
			post('sheet-order', { sheet: form.getAttribute('data-sheet'), name: form.elements.name.value, email: form.elements.email.value, website: form.elements.website.value, region: region, token: token }).then(function (res) {
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
<h2>Mây đang soạn những bản đầu tiên</h2>
<p>Sheet sẽ có ở đây trong thời gian tới. Trong lúc chờ, bạn xem các khóa học của Mây nhé.</p>
<p class="mp-lost-go"><a class="mp-main" href="<?php echo esc_url( home_url( '/#chon-khoa' ) ); ?>">Xem các khóa học</a></p>
</div>
<?php endif; ?>
</main>
<?php
get_footer();
