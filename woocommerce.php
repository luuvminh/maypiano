<?php
/**
 * WooCommerce pages (pay for order, order received, account).
 */
get_header();
echo '<main class="mp-plain mp-wide">';
woocommerce_content();
echo '</main>';
get_footer();
