<?php
/**
 * Public site header.
 *
 * @package Wuhanyingyuan
 */

$phone = (string) yy_global_setting('phone', '18107552669');
$phone_href = preg_replace('/[^\d+]/', '', $phone);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#002959">
    <meta name="format-detection" content="telephone=no">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#main-content">跳到主要内容</a>

<header class="site-header" id="siteHeader">
    <div class="container">
        <nav class="nav" aria-label="主导航">
            <a class="logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr((string) yy_global_setting('brand_name', get_bloginfo('name'))); ?>首页">
                <?php
                if (has_custom_logo()) {
                    the_custom_logo();
                } else {
                    yy_render_setting_image('logo_id', 'images/logo1.jpg', [
                        'class' => 'logo-image',
                        'width' => 168,
                        'height' => 56,
                        'alt' => (string) yy_global_setting('brand_name', get_bloginfo('name')),
                    ]);
                }
                ?>
            </a>

            <button class="nav-toggle" id="navToggle" type="button" aria-expanded="false" aria-controls="primaryNavigation">
                <span class="sr-only">打开导航菜单</span>
                <span></span>
                <span></span>
                <span></span>
            </button>

            <?php yy_render_primary_navigation(); ?>

            <button class="btn nav-cta" type="button" data-wechat-trigger>免费诊断账户</button>
        </nav>
    </div>
</header>

<main id="main-content">
