<?php
$post = get_post($post);
$post_id  = (empty($post->ID) ? 0 : $post->ID);
$label  = 'pwbox-' . (empty($post->ID) ? rand() : $post->ID);
ob_start(); ?>
    <div class="u-password-control u-clearfix u-sheet u-valign-middle u-sheet-1"><form action="<?php echo APP_PLUGIN_URL . 'includes/templates/passwordProtect/action.php'; ?>" class="post-password-form" method="post">
            <p class="u-text-variant"><?php echo __('This content is password protected. To view it please enter your password below:', 'default'); ?></p>
            <p class="u-text-variant"><label for="<?php echo $label; ?>"><?php echo __('Password:', 'default'); ?> <input name="password" id="<?php echo $label; ?>" type="password" size="20"></label><input class="u-form-submit" type="submit" name="Submit" value="<?php echo esc_attr_x('Enter', 'post password form', 'default'); ?>"></p>
            <input type="hidden" name="post_id" value="<?php echo $post_id; ?>">
            <input type="hidden" name="password_hash" value="*****">
        </form>
    </div>
<?php $output = ob_get_clean(); ?>