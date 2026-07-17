<?php
/**
 * Header template.
 *
 * @package Show_Focus_Prototype
 */

if (!defined('ABSPATH')) {
    exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="site-shell">
    <header class="site-header animate-up">
        <div class="site-header-inner">
            <a class="brand" href="<?php echo esc_url(home_url('/')); ?>">
                <span class="brand-chip">IVMF</span>
                <span class="brand-name">Institute for Veterans and Military Families</span>
            </a>
            <nav aria-label="Primary menu">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container' => false,
                    'menu_class' => 'site-nav',
                    'fallback_cb' => 'show_focus_nav_fallback',
                    'depth' => 1,
                ]);
                ?>
            </nav>
        </div>
    </header>
