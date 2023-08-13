<?php
if (!isset($_POST['password']) || !isset($_POST['password_hash']) || !isset($_POST['post_id'])) {
    exit;
}

require dirname(dirname(dirname(dirname(dirname(dirname(dirname(__FILE__))))))) . "/wp-load.php";

if (!array_key_exists('password', $_POST)) {
    wp_safe_redirect(wp_get_referer());
    exit;
}

require_once ABSPATH . WPINC . '/class-phpass.php';
$hasher = new PasswordHash(8, true);

/**
 * Filters the life span of the post password cookie.
 *
 * By default, the cookie expires 10 days from creation. To turn this
 * into a session cookie, return 0.
 *
 * @since 3.7.0
 */
$expire  = apply_filters('post_password_expires', time() + 10 * DAY_IN_SECONDS);
$referer = wp_get_referer();

if ($referer) {
    $secure = ('https' === parse_url($referer, PHP_URL_SCHEME));
} else {
    $secure = false;
}

$post = get_post($_POST['post_id']);
$password = $_POST['password'];
if ($post && $post->post_password === $_POST['password_hash']) {
    $password = $_POST['password_hash'];
}
setcookie('wp-postpass_' . COOKIEHASH, $hasher->HashPassword(wp_unslash($password)), $expire, COOKIEPATH, COOKIE_DOMAIN, $secure);

wp_safe_redirect(wp_get_referer());
exit;
