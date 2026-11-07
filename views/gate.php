<?php
/** @var \Hachitechs\HideguardWordPress\Plugin $this */
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>">
<title><?php esc_html_e('Access temporarily blocked', 'hachitechs-hideguard'); ?></title>
<?php wp_head(); ?></head>
<body inert data-hachitech-ab-inert>
<section id="hachitech-ab-gate" class="hachitech-ab-gate"
    data-page="gate" data-state="<?= esc_attr($this->initialState) ?>">
    <div class="hachitech-ab-message">
        <h2><?php echo esc_html($this->initialState === 'error'
            ? __('Unable to verify access', 'hachitechs-hideguard')
            : __('Access temporarily blocked', 'hachitechs-hideguard')); ?></h2>
        <p role="status"><?php echo esc_html($this->initialState === 'error'
            ? __('Access could not be verified. Please try again.', 'hachitechs-hideguard')
            : __('Please try again after the block period.', 'hachitechs-hideguard')); ?></p>
        <button type="button" class="hachitech-ab-retry" data-action="retry">
            <?php esc_html_e('Try again', 'hachitechs-hideguard'); ?>
        </button>
    </div>
</section>
<?php wp_footer(); ?></body></html>
