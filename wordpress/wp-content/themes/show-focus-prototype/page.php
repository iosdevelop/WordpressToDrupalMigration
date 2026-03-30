<?php
/**
 * Page template.
 *
 * @package Show_Focus_Prototype
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main class="entry-shell">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article class="entry-panel animate-up delay-2">
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
                <div class="entry-meta">
                    Last updated <?php echo esc_html(get_the_modified_date('F j, Y')); ?>
                </div>
            </article>
        <?php endwhile; ?>
    <?php endif; ?>
</main>
<?php
get_footer();
