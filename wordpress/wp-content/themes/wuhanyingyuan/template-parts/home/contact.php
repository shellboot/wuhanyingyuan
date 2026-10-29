<?php
/**
 * Homepage contact call to action.
 *
 * @package Wuhanyingyuan
 */

$phone = (string) yy_global_setting('phone', '');
$phone_href = preg_replace('/[^\d+]/', '', $phone);
?>
<section class="section cta" id="contact" aria-labelledby="contactTitle">
    <div class="container">
        <h2 id="contactTitle"><?php echo esc_html((string) yy_setting('contact_title')); ?></h2>
        <p><?php echo esc_html((string) yy_setting('contact_description')); ?></p>
        <button class="btn" type="button" data-wechat-trigger><?php echo esc_html((string) yy_setting('contact_button_text')); ?></button>
        <div class="contact-info">
            <?php if ($phone !== '') : ?><div>联系电话：<a href="tel:<?php echo esc_attr($phone_href); ?>"><?php echo esc_html($phone); ?></a></div><?php endif; ?>
            <div><?php echo esc_html((string) yy_setting('contact_secondary')); ?></div>
            <?php if ((string) yy_global_setting('work_hours') !== '') : ?><div>工作时间：<?php echo esc_html((string) yy_global_setting('work_hours')); ?></div><?php endif; ?>
        </div>
    </div>
</section>
