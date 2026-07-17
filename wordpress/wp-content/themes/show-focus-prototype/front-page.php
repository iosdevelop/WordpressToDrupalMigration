<?php
/**
 * Front page template aligned with the Drupal Canvas demonstration.
 *
 * @package Show_Focus_Prototype
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main class="canvas-main" id="main-content">
    <section class="canvas-hero canvas-section animate-up delay-1" aria-labelledby="canvas-demo-title">
        <div class="canvas-hero-copy">
            <p class="canvas-eyebrow">Syracuse University &middot; IVMF</p>
            <h1 id="canvas-demo-title">Empowering the military-connected community</h1>
            <p>
                A WordPress demonstration aligned with the Drupal Canvas component
                configuration: modular, accessible, and ready for migrated content.
            </p>
            <a class="canvas-button" href="#canvas-patterns">Explore the programs</a>
        </div>
        <div class="canvas-hero-art" aria-hidden="true">
            <span class="canvas-hero-ring"></span>
            <strong>Serve.<br>Connect.<br>Advance.</strong>
        </div>
    </section>

    <section class="canvas-section" id="canvas-patterns" aria-labelledby="programs-title">
        <div class="canvas-section-heading">
            <p class="canvas-eyebrow">Three Card Container</p>
            <h2 id="programs-title">Find your next step</h2>
            <p>Drupal Canvas composes a layout container with three reusable card components.</p>
        </div>
        <div class="canvas-card-grid">
            <article class="canvas-card">
                <span class="canvas-card-number">01</span>
                <h3>Career training</h3>
                <p>Build practical skills and translate military experience into a meaningful civilian career.</p>
                <a href="<?php echo esc_url(home_url('/service-strategy-workshop/')); ?>">Explore training <span aria-hidden="true">&rarr;</span></a>
            </article>
            <article class="canvas-card canvas-card-accent">
                <span class="canvas-card-number">02</span>
                <h3>Entrepreneurship</h3>
                <p>Turn an idea into a resilient business with expert instruction and a nationwide network.</p>
                <a href="<?php echo esc_url(home_url('/service-drupal-build/')); ?>">Build a business <span aria-hidden="true">&rarr;</span></a>
            </article>
            <article class="canvas-card">
                <span class="canvas-card-number">03</span>
                <h3>Community support</h3>
                <p>Connect to resources designed for service members, veterans, and military families.</p>
                <a href="<?php echo esc_url(home_url('/resource-guide-redirect-strategy/')); ?>">Find resources <span aria-hidden="true">&rarr;</span></a>
            </article>
        </div>
    </section>

    <section class="canvas-checkerboard canvas-section" aria-label="Canvas checkerboard pattern">
        <div class="canvas-checkerboard-art canvas-art-training" aria-hidden="true"><span>IVMF</span></div>
        <div class="canvas-checkerboard-copy">
            <p class="canvas-eyebrow">Two Card Checkerboard</p>
            <h2>Opportunity after service</h2>
            <p>Alternating media and text modules create a strong editorial rhythm while keeping every section independently reusable.</p>
            <a class="canvas-text-link" href="<?php echo esc_url(home_url('/service-content-audit/')); ?>">See migration-ready content <span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="canvas-checkerboard-copy">
            <p class="canvas-eyebrow">Reusable component</p>
            <h2>Built around real journeys</h2>
            <p>Canvas component inputs map cleanly to headings, rich text, media, captions, and calls to action.</p>
            <a class="canvas-text-link" href="<?php echo esc_url(home_url('/resource-checklist-content-inventory/')); ?>">Learn how it maps <span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="canvas-checkerboard-art canvas-art-community" aria-hidden="true">
            <span>125</span>
            <small>destination pages</small>
        </div>
    </section>

    <section class="canvas-two-column canvas-section" aria-labelledby="impact-title">
        <div>
            <p class="canvas-eyebrow">Two Column Text</p>
            <h2 id="impact-title">One flexible system.<br>Many stories.</h2>
        </div>
        <div>
            <p class="canvas-lead">The WordPress front page now mirrors the visual grammar exposed by the Drupal Canvas implementation.</p>
            <p>This gives stakeholders a consistent source-and-destination story while keeping each CMS independently runnable for the live demo.</p>
        </div>
    </section>

    <section class="canvas-accordion-section canvas-section" aria-labelledby="faq-title">
        <div class="canvas-section-heading">
            <p class="canvas-eyebrow">Accordion Container 3</p>
            <h2 id="faq-title">How the reconstruction works</h2>
        </div>
        <div class="canvas-accordion-list">
            <details open>
                <summary>What is synchronized between WordPress and Drupal?</summary>
                <p>The content hierarchy, pattern names, visual system, responsive behavior, and calls to action now match across both front pages.</p>
            </details>
            <details>
                <summary>Is WordPress running Drupal Canvas?</summary>
                <p>No. WordPress renders a theme-native source analogue, while Drupal uses the destination Canvas component model.</p>
            </details>
            <details>
                <summary>Can the migrated pages use this design?</summary>
                <p>Yes. Existing WordPress fixture pages remain available, and Drupal provides the component-driven destination for migrated content.</p>
            </details>
        </div>
    </section>
</main>

<?php
get_footer();
