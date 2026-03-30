<?php
/**
 * Theme setup for Show Focus Prototype.
 *
 * @package Show_Focus_Prototype
 */

if (!defined('ABSPATH')) {
    exit;
}

function show_focus_setup(): void
{
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);

    register_nav_menus([
        'primary' => __('Primary Navigation', 'show-focus-prototype'),
    ]);
}
add_action('after_setup_theme', 'show_focus_setup');

function show_focus_enqueue_assets(): void
{
    wp_enqueue_style(
        'show-focus-fonts',
        'https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap',
        [],
        null
    );

    wp_enqueue_style(
        'show-focus-style',
        get_stylesheet_uri(),
        ['show-focus-fonts'],
        wp_get_theme()->get('Version')
    );
}
add_action('wp_enqueue_scripts', 'show_focus_enqueue_assets');

function show_focus_nav_fallback(): void
{
    $default_links = [
        '/' => __('Home', 'show-focus-prototype'),
        '/about-company-overview/' => __('About', 'show-focus-prototype'),
        '/service-strategy-workshop/' => __('Services', 'show-focus-prototype'),
        '/resource-guide-redirect-strategy/' => __('Resources', 'show-focus-prototype'),
        '/contact-sales/' => __('Contact', 'show-focus-prototype'),
    ];

    echo '<ul class="site-nav">';

    foreach ($default_links as $path => $label) {
        $url = home_url($path);
        $is_current = untrailingslashit(home_url(add_query_arg([]))) === untrailingslashit($url);
        $class = $is_current ? ' class="current-menu-item"' : '';

        echo '<li' . $class . '>';
        echo '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        echo '</li>';
    }

    echo '</ul>';
}
