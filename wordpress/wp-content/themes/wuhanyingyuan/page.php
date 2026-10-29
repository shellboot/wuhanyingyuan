<?php
/**
 * Generic WordPress page.
 *
 * @package Wuhanyingyuan
 */

get_header();
?>
<?php while (have_posts()) : the_post(); ?>
    <section class="page-hero">
        <div class="container"><h1><?php the_title(); ?></h1></div>
    </section>
    <div class="generic-page">
        <div class="container">
            <article class="page-content"><?php the_content(); ?></article>
        </div>
    </div>
<?php endwhile; ?>
<?php get_footer(); ?>
