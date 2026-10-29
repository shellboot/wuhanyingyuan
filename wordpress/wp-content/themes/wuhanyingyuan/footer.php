<?php
/**
 * Public site footer and shared overlays.
 *
 * @package Wuhanyingyuan
 */

$phone = (string) yy_global_setting('phone', '18107552669');
$phone_href = preg_replace('/[^\d+]/', '', $phone);
$work_hours = (string) yy_global_setting('work_hours', '');
?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p><?php echo esc_html((string) yy_global_setting('footer_text', '© 2026 盈圆 · 武汉信息流广告线索服务商')); ?></p>
        <?php if ((string) yy_global_setting('beian_text', '') !== '') : ?>
            <p><?php echo esc_html((string) yy_global_setting('beian_text')); ?></p>
        <?php endif; ?>
        <?php if ($phone !== '') : ?>
            <p class="footer-contact">
                <a href="tel:<?php echo esc_attr($phone_href); ?>"><?php echo esc_html($phone); ?></a>
                <?php if ($work_hours !== '') : ?>
                    <span class="footer-hours"><?php echo esc_html($work_hours); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</footer>

<?php if ($phone !== '') : ?>
    <a class="float-phone" href="tel:<?php echo esc_attr($phone_href); ?>" aria-label="拨打电话 <?php echo esc_attr($phone); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92Z"/>
        </svg>
        <span class="float-tip">拨打电话 <?php echo esc_html($phone); ?></span>
    </a>
<?php endif; ?>

<button class="float-wechat" type="button" data-wechat-trigger aria-label="微信咨询">
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M9.3 4C5.3 4 2 6.7 2 10c0 1.9 1.1 3.5 2.8 4.6l-.7 2.2 2.6-1.3c.8.2 1.7.3 2.6.3h.5c-.1-.4-.2-.9-.2-1.3 0-3 3-5.4 6.7-5.4h.4C15.9 6 12.9 4 9.3 4Zm-2.5 3.1c.5 0 .9.4.9.9s-.4.9-.9.9-.9-.4-.9-.9.4-.9.9-.9Zm5.2 0c.5 0 .9.4.9.9s-.4.9-.9.9-.9-.4-.9-.9.4-.9.9-.9Z"/>
        <path d="M22 14.5c0-2.7-2.7-4.8-6-4.8s-6 2.1-6 4.8 2.7 4.8 6 4.8c.7 0 1.4-.1 2-.3l2.2 1.1-.6-1.8c1.5-.9 2.4-2.3 2.4-3.8Zm-8-1.1c.4 0 .7.3.7.7s-.3.7-.7.7-.7-.3-.7-.7.3-.7.7-.7Zm4 0c.4 0 .7.3.7.7s-.3.7-.7.7-.7-.3-.7-.7.3-.7.7-.7Z"/>
    </svg>
    <span class="float-tip">点我加微信咨询</span>
</button>

<div class="modal-mask" id="wechatModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="wechatModalTitle">
        <button class="modal-close" id="modalClose" type="button" aria-label="关闭弹窗">×</button>
        <div class="wx-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="M9.3 4C5.3 4 2 6.7 2 10c0 1.9 1.1 3.5 2.8 4.6l-.7 2.2 2.6-1.3c.8.2 1.7.3 2.6.3h.5c-.1-.4-.2-.9-.2-1.3 0-3 3-5.4 6.7-5.4h.4C15.9 6 12.9 4 9.3 4Z"/>
                <path d="M22 14.5c0-2.7-2.7-4.8-6-4.8s-6 2.1-6 4.8 2.7 4.8 6 4.8c.7 0 1.4-.1 2-.3l2.2 1.1-.6-1.8c1.5-.9 2.4-2.3 2.4-3.8Z"/>
            </svg>
        </div>
        <h2 id="wechatModalTitle">添加微信咨询</h2>
        <p class="tip">长按 / 扫描二维码添加，备注“官网咨询”</p>
        <div class="qr-wrap">
            <?php
            yy_render_setting_image('wechat_qr_id', 'images/whyy.jpg', [
                'width' => 200,
                'height' => 200,
                'alt' => '盈圆微信咨询二维码',
                'loading' => 'lazy',
                'decoding' => 'async',
            ]);
            ?>
            <p class="qr-tip">长按二维码识别添加</p>
        </div>
        <button class="close-btn" id="modalCloseBottom" type="button">关闭</button>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>
