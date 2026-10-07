<?php
/**
 * One course.
 */
get_header();
$mp = maypiano_course_view( get_queried_object_id() );
$mp_check = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"></path></svg>';
$mp_lock  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"></rect><path d="M8 11V8a4 4 0 0 1 8 0v3"></path></svg>';
?>
<main class="mpc">

<section class="mpc-hero">
<div class="mpc-hero-text">
<h1><?php echo esc_html( $mp['title'] ); ?></h1>
<?php if ( '' !== $mp['intro'] ) : ?>
<p class="mpc-intro"><?php echo esc_html( $mp['intro'] ); ?></p>
<?php endif; ?>
<div class="mpc-buy">
<?php if ( $mp['enrolled'] ) : ?>
	<?php if ( '' !== $mp['first'] ) : ?>
<a class="mpc-btn" href="<?php echo esc_url( $mp['first'] ); ?>">Vào học</a>
	<?php endif; ?>
<?php else : ?>
	<?php if ( '' !== $mp['price'] ) : ?>
<span class="mpc-price" data-mp-price="<?php echo esc_attr( $mp['key'] ); ?>"><?php echo esc_html( $mp['price'] ); ?></span>
	<?php endif; ?>
<a class="mpc-btn" href="<?php echo esc_url( $mp['buy'] ); ?>">Đăng ký khóa này</a>
<?php endif; ?>
<a class="mpc-more" href="#noi-dung">Xem nội dung khóa học</a>
</div>
<?php if ( '' !== $mp['meta'] ) : ?>
<p class="mpc-meta"><?php echo esc_html( $mp['meta'] ); ?>, mỗi bài có video Mây hướng dẫn</p>
<?php endif; ?>
</div>
<img class="mpc-photo" src="<?php echo esc_url( $mp['photo'] ); ?>" alt="<?php echo esc_attr( $mp['alt'] ); ?>" width="1000" height="666" decoding="async">
</section>

<?php if ( $mp['gets'] ) : ?>
<section class="mpc-gets" aria-labelledby="mpc-gets-h">
<h2 id="mpc-gets-h">Sau khóa này bạn làm được</h2>
<ul>
	<?php foreach ( $mp['gets'] as $mp_get ) : ?>
<li><?php echo $mp_check; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $mp_get ); ?></span></li>
	<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>

<?php if ( $mp['parts'] ) : ?>
<section class="mpc-list" id="noi-dung" aria-labelledby="mpc-list-h">
<h2 id="mpc-list-h">Nội dung khóa học</h2>
<p class="mpc-lead"><?php echo $mp['enrolled'] ? 'Bạn bấm vào bài nào để học bài đó nhé.' : 'Bạn bấm vào từng phần để xem Mây dạy những bài nào.'; ?></p>
	<?php foreach ( $mp['parts'] as $mp_i => $mp_part ) : ?>
<details class="mpc-part"<?php echo 0 === $mp_i ? ' open' : ''; ?>>
<summary><span class="mpc-part-name"><?php echo esc_html( $mp_part['title'] ); ?></span><span class="mpc-part-meta"><?php echo esc_html( $mp_part['meta'] ); ?></span></summary>
<ol>
		<?php foreach ( $mp_part['lessons'] as $mp_row ) : ?>
<li>
<span class="mpc-no"><?php echo (int) $mp_row['no']; ?></span>
			<?php if ( '' !== $mp_row['url'] ) : ?>
<a class="mpc-name" href="<?php echo esc_url( $mp_row['url'] ); ?>"><?php echo esc_html( $mp_row['title'] ); ?></a>
			<?php else : ?>
<span class="mpc-name"><?php echo esc_html( $mp_row['title'] ); ?></span>
			<?php endif; ?>
			<?php if ( $mp_row['free'] ) : ?>
<span class="mpc-free">Xem thử</span>
			<?php endif; ?>
<span class="mpc-len"><?php echo esc_html( $mp_row['length'] ); ?></span>
			<?php if ( '' === $mp_row['url'] ) : ?>
<span class="mpc-lock" title="Mở sau khi đăng ký"><?php echo $mp_lock; // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text">Mở sau khi đăng ký</span></span>
			<?php endif; ?>
</li>
		<?php endforeach; ?>
</ol>
</details>
	<?php endforeach; ?>
</section>
<?php endif; ?>

<section class="mpc-may">
<div>
<h2>Chào bạn, mình là Mây!</h2>
<p>Mình tên đầy đủ là Phan Trần Hải Mây. Bén duyên với piano từ những bài đàn đệm ở nhà thờ thời thơ ấu, tính đến nay Mây đã có hơn 15 năm chơi đàn và giảng dạy. <strong>Hiện tại, Mây đang dạy piano tại Melbourne</strong> và sở hữu kênh YouTube với hàng trăm bài giảng miễn phí dành cho cộng đồng.</p>
<p>Mây luôn ưu tiên phương pháp dạy căn bản gắn liền với thực hành trên bài hát thực tế. Mọi sheet nhạc Mây biên soạn đều được thiết kế rõ ràng, dễ hiểu, giúp học viên ở mọi lứa tuổi tập luyện hiệu quả và tràn đầy hứng khởi.</p>
</div>
<img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/p01.jpg' ) ); ?>" alt="Chân dung Mây trong tà áo dài" width="797" height="1000" loading="lazy" decoding="async">
</section>

<?php if ( ! $mp['enrolled'] ) : ?>
<section class="mpc-end">
<h2>Mình bắt đầu nhé?</h2>
<p>Đăng ký xong, Mây mở khóa học cho bạn và gửi cách vào học qua email.</p>
<div class="mpc-buy">
	<?php if ( '' !== $mp['price'] ) : ?>
<span class="mpc-price" data-mp-price="<?php echo esc_attr( $mp['key'] ); ?>"><?php echo esc_html( $mp['price'] ); ?></span>
	<?php endif; ?>
<a class="mpc-btn" href="<?php echo esc_url( $mp['buy'] ); ?>">Đăng ký khóa này</a>
</div>
</section>
<?php endif; ?>

</main>
<?php if ( '' !== $mp['key'] && ! $mp['enrolled'] ) : ?>
<script>
/* The page may be a saved copy, so the price for where the visitor is gets asked for afresh. */
(function () {
	var cfg = window.MayPianoCfg, spots = document.querySelectorAll('[data-mp-price]');
	if (!cfg || !cfg.rest || !spots.length || !window.fetch) { return; }
	fetch(cfg.rest + 'config', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }).then(function (r) { return r.json(); }).then(function (c) {
		var cur = { VN: 'VND', AU: 'AUD' }[c.region] || 'USD';
		spots.forEach(function (el) {
			var p = c.prices && c.prices[el.getAttribute('data-mp-price')], v = p && p[cur];
			if (!v) { return; }
			if (cur === 'VND') { el.textContent = Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + 'đ'; return; }
			var t = Number(v).toFixed(2).replace(/\.00$/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
			el.textContent = (cur === 'AUD' ? 'A$' : 'US$') + t;
		});
	}).catch(function () {});
})();
</script>
<?php endif; ?>
<?php
get_footer();
