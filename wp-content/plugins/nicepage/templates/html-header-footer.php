<?php
defined('ABSPATH') or die;
/**
 * Page template with raw html with header & footer
 */

global $post;

ob_start();
get_header();
$header = ob_get_clean();

if (Nicepage::isNpTheme() && function_exists('renderTemplate')) {
    renderTemplate($header, '', 'echo', 'header');
} else {
    echo $header;
}

the_post();
the_content();

ob_start();
get_footer();
$footer = ob_get_clean();

if (Nicepage::isNpTheme() && function_exists('renderTemplate')) {
    renderTemplate($footer, '', 'echo', 'footer');
} else {
    echo $footer;
} ?>
