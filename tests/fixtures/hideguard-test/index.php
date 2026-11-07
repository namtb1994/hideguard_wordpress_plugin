<?php get_header(); ?>
<main>
    Fixture storefront
    <?php while (have_posts()) : ?>
        <?php the_post(); ?>
        <?php the_content(); ?>
    <?php endwhile; ?>
</main>
<?php get_footer(); ?>
