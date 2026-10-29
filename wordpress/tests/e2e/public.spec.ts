import { expect, test } from '@playwright/test';

test.describe('public site', () => {
  test('homepage responds with WordPress and complete sections', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).toContainText('盈圆');
    await expect(page.locator('link[href*="wp-content"]').first()).toBeAttached();

    for (const id of ['platforms', 'services', 'process', 'cases', 'about', 'stats', 'faq', 'contact']) {
      await expect(page.locator(`#${id}`)).toBeVisible();
    }
  });

  test('navigation, wechat modal and mobile menu work', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#primaryNavigation')).toBeVisible();
    await expect(page.locator('.site-footer')).toBeVisible();

    await page.locator('[data-wechat-trigger]').first().click();
    await expect(page.locator('#wechatModal')).toHaveClass(/is-active/);
    await page.keyboard.press('Escape');
    await expect(page.locator('#wechatModal')).not.toHaveClass(/is-active/);

    if ((page.viewportSize()?.width || 1440) <= 860) {
      await page.locator('#navToggle').click();
      await expect(page.locator('#navToggle')).toHaveAttribute('aria-expanded', 'true');
      await expect(page.locator('#primaryNavigation')).toHaveClass(/is-open/);
    }
  });

  test('carousel switches slides', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');

    const dots = page.locator('#carouselDots .carousel-dot');
    const slides = page.locator('.carousel-slide');
    await expect(dots.first()).toBeVisible();
    expect(await dots.count()).toBeGreaterThan(1);

    await page.locator('#carouselNext').click();
    await expect(dots.nth(1)).toHaveAttribute('aria-current', 'true');
    await expect(slides.nth(1)).toHaveClass(/is-active/);
  });

  test('news archive and article detail use WordPress content', async ({ page }) => {
    const response = await page.goto('/news/');
    expect(response?.status()).toBe(200);
    await expect(page.locator('.news-card').first()).toBeVisible();

    const articleLink = page.locator('.news-card a').first();
    await articleLink.click();
    await expect(page.locator('article.single-article h1')).toBeVisible();
    await expect(page.locator('.article-content')).toBeVisible();
  });

  test('seo and security headers are present once', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.headers()['x-content-type-options']).toBe('nosniff');
    expect(response?.headers()['referrer-policy']).toBe('strict-origin-when-cross-origin');
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
    await expect(page.locator('script[type="application/ld+json"]').first()).toBeAttached();
  });

  test('legacy news URL redirects permanently', async ({ request }) => {
    await request.get('/');
    const response = await request.get('/news.html', { maxRedirects: 0 });
    expect([301, 308]).toContain(response.status());
    expect(response.headers()['location']).toContain('/news/');
  });

  test('mobile layout has no horizontal overflow', async ({ page }) => {
    await page.goto('/');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);

    const footerOverflow = await page.locator('.footer-inner p').evaluateAll((elements) =>
      Math.max(0, ...elements.map((element) => element.scrollWidth - element.clientWidth))
    );
    expect(footerOverflow).toBeLessThanOrEqual(1);
  });
});
