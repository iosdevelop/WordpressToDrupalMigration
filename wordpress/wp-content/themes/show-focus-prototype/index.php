<?php
/**
 * Fallback template.
 *
 * @package Show_Focus_Prototype
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main class="entry-shell">
    <section class="entry-panel animate-up delay-2">
        <h1 class="entry-title">Site Content</h1>
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <article class="content-card">
                    <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                    <p><?php echo esc_html(wp_trim_words(get_the_excerpt(), 30)); ?></p>
                </article>
            <?php endwhile; ?>
        <?php else : ?>
            <p>No content found yet.</p>
        <?php endif; ?>
    </section>
</main>
<?php
get_footer();
