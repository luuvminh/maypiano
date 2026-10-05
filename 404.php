<?php
/**
 * A link that leads nowhere.
 */
get_header();
?>
<main class="mp-plain mp-lost">
<h1>Mây không tìm thấy trang này</h1>
<p>Đường dẫn này không còn, hoặc đã bị gõ nhầm một chữ. Bạn về trang chủ để xem các khóa học nhé. Nếu bạn đã mua khóa, bạn bấm Vào học.</p>
<p class="mp-lost-go"><a class="mp-main" href="<?php echo esc_url( home_url( '/' ) ); ?>">Về trang chủ</a><a href="<?php echo esc_url( is_user_logged_in() ? maypiano_learn_url() : maypiano_login_url() ); ?>">Vào học</a></p>
</main>
<?php
get_footer();
