<?php
/**
 * Front page template.
 *
 * @package Show_Focus_Prototype
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$published_pages = (int) wp_count_posts('page')->publish;

$legacy_query = new WP_Query([
    'post_type' => 'page',
    'post_status' => 'publish',
    'meta_key' => 'legacy_url',
    'posts_per_page' => 1,
    'fields' => 'ids',
]);
$legacy_mapped_pages = (int) $legacy_query->found_posts;
wp_reset_postdata();

$recent_pages = new WP_Query([
    'post_type' => 'page',
    'post_status' => 'publish',
    'posts_per_page' => 4,
    'orderby' => 'modified',
    'order' => 'DESC',
]);
?>
<section class="hero animate-up delay-1">
    <div class="hero-grid">
        <div>
            <span class="eyebrow">Migration Demo Theme</span>
            <h1>Show Focus Design for a High-Impact Prototype</h1>
            <p>
                This visual layer turns fixture content into a confident presentation surface
                for demos, stakeholder reviews, and migration narrative walkthroughs.
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>">Manage Pages</a>
                <a class="btn" href="<?php echo esc_url(home_url('/about-company-overview/')); ?>">View Sample Page</a>
            </div>
        </div>
        <div class="metric-grid">
            <article class="metric-card">
                <strong><?php echo esc_html(number_format_i18n($published_pages)); ?></strong>
                <span>Published Pages</span>
            </article>
            <article class="metric-card">
                <strong><?php echo esc_html(number_format_i18n($legacy_mapped_pages)); ?></strong>
                <span>Legacy URL Mappings</span>
            </article>
            <article class="metric-card">
                <strong>275 &#8594; 125</strong>
                <span>Consolidation Model</span>
            </article>
            <article class="metric-card">
                <strong>CSV + API</strong>
                <span>Migration Inputs</span>
            </article>
        </div>
    </div>
</section>

<section class="content-grid">
    <article class="panel animate-up delay-2">
        <div class="panel-body">
            <h2>Recently Updated Pages</h2>
            <?php if ($recent_pages->have_posts()) : ?>
                <?php while ($recent_pages->have_posts()) : $recent_pages->the_post(); ?>
                    <div class="content-card">
                        <h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                        <p>Updated <?php echo esc_html(get_the_modified_date('F j, Y')); ?></p>
                    </div>
                <?php endwhile; ?>
            <?php else : ?>
                <p>No pages found yet. Generate fixture content to populate this section.</p>
            <?php endif; ?>
            <?php wp_reset_postdata(); ?>
        </div>
    </article>

    <aside class="panel animate-up delay-3">
        <div class="panel-body">
            <h3>Demo Highlights</h3>
            <div class="pill-list">
                <span class="pill">Deterministic Fixtures</span>
                <span class="pill">Redirect Strategy</span>
                <span class="pill">SEO Metadata</span>
                <span class="pill">Template Mapping</span>
                <span class="pill">Scale Narrative</span>
            </div>
        </div>
    </aside>
</section>

<?php
get_footer();
