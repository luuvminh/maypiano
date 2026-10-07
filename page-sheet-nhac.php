<?php
/**
 * Sheet nhạc (the page with the address /sheet-nhac/).
 */
get_header();
$sheets = maypiano_sheets();
?>
<main class="mp-plain mp-wide mp-sheets">
<p class="mp-kicker">Sheet nhạc</p>
<h1>Sheet nhạc Mây soạn</h1>
<p class="mp-lead">Đây là nơi Mây để các bản sheet piano do Mây soạn. Bạn in ra giấy hoặc mở trên máy rồi tập theo nhé.</p>
<?php if ( $sheets ) : ?>
<ul class="mp-sheet-list">
	<?php foreach ( $sheets as $sheet ) : ?>
<li>
<div class="mp-sheet-text">
<h2><?php echo esc_html( $sheet['title'] ); ?></h2>
		<?php if ( '' !== $sheet['level'] ) : ?>
<p class="mp-sheet-level"><?php echo esc_html( $sheet['level'] ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $sheet['note'] ) : ?>
<p><?php echo esc_html( $sheet['note'] ); ?></p>
		<?php endif; ?>
</div>
		<?php if ( '' !== $sheet['url'] ) : ?>
<a class="mp-sheet-get" href="<?php echo esc_url( $sheet['url'] ); ?>">Tải sheet<span class="screen-reader-text"> <?php echo esc_html( $sheet['title'] ); ?></span> <span aria-hidden="true">›</span></a>
		<?php endif; ?>
</li>
	<?php endforeach; ?>
</ul>
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
