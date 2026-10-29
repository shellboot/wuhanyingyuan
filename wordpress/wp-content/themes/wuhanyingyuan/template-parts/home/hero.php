<?php
/**
 * Homepage hero.
 *
 * @package Wuhanyingyuan
 */
?>
<section class="hero" aria-labelledby="heroTitle">
    <div class="container">
        <?php if ((string) yy_setting('hero_tagline') !== '') : ?>
            <span class="hero-badge"><?php echo esc_html((string) yy_setting('hero_tagline')); ?></span>
        <?php endif; ?>
        <h1 id="heroTitle">
            <?php echo esc_html((string) yy_setting('hero_title')); ?>
            <?php if ((string) yy_setting('hero_highlight') !== '') : ?>
                <em><?php echo esc_html((string) yy_setting('hero_highlight')); ?></em>
            <?php endif; ?>
        </h1>
        <p><?php echo esc_html((string) yy_setting('hero_description')); ?></p>
        <div class="hero-actions">
            <button class="btn" type="button" data-wechat-trigger><?php echo esc_html((string) yy_setting('hero_primary_text')); ?></button>
            <button class="btn btn-outline" type="button" data-wechat-trigger><?php echo esc_html((string) yy_setting('hero_secondary_text')); ?></button>
        </div>
    </div>
</section>
