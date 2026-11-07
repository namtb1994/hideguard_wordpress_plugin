<?php

use Hachitechs\HideguardWordPress\Settings;

?>
<div class="wrap">
    <h1><?php esc_html_e('Hachitech Hideguard', 'hachitechs-hideguard'); ?></h1>
    <?php settings_errors(Settings::OPTION); ?>
    <form action="options.php" method="post">
        <?php settings_fields(Settings::OPTION);
        do_settings_sections(Settings::OPTION);
        submit_button(); ?>
    </form>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <input type="hidden" name="action" value="hachitech_hideguard_reset">
        <?php wp_nonce_field('hachitech_hideguard_reset');
        submit_button(__('Reset block list', 'hachitechs-hideguard'), 'secondary'); ?>
    </form>
</div>
