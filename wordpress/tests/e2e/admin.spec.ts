import { expect, test } from '@playwright/test';
import {
  disablePlaygroundAutoLogin,
  disablePlaygroundAutoLoginForPage,
  readAdminRestNonce,
  uploadTestImage,
} from './support';

const username = process.env.TEST_EDITOR_USER || 'editor';
const password = process.env.TEST_EDITOR_PASSWORD || '';

test.describe('WordPress management', () => {
  test('content editor publishes news while plugin and settings pages stay protected', async ({ page, request }) => {
    test.skip(!password, 'TEST_EDITOR_PASSWORD is required for the admin workflow test');
    test.setTimeout(90_000);

    await disablePlaygroundAutoLoginForPage(page);
    await disablePlaygroundAutoLogin(request);
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(username);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await expect(page).toHaveURL(/wp-admin/);
    await expect(page.locator('body.wp-admin')).toBeVisible();
    await expect(page.locator('#wpbody')).toBeVisible();

    await page.goto('/wp-admin/post-new.php');
    await expect(page.locator('body')).not.toContainText('Sorry, you are not allowed');
    const restNonce = await readAdminRestNonce(page);
    const restHeaders = { 'X-WP-Nonce': restNonce };
    const marker = `Editor workflow ${Date.now()}`;
    let articleId = 0;

    const image = await uploadTestImage(
      page.request,
      restHeaders,
      `editor-${Date.now()}.png`
    );
    expect(image.id).toBeGreaterThan(0);

    try {
      const create = await page.request.post('/wp-json/wp/v2/posts', {
        headers: restHeaders,
        data: {
          title: marker,
          content: `<p>${marker}</p>`,
          status: 'draft',
          featured_media: image.id,
        },
      });
      expect(create.status(), await create.text()).toBe(201);
      const draft = await create.json();
      articleId = draft.id;
      expect(draft.status).toBe('draft');
      expect(draft.featured_media).toBe(image.id);

      const anonymousDraft = await request.get(`/?p=${articleId}`);
      expect(anonymousDraft.status()).toBe(404);

      await page.goto(`/wp-admin/post.php?post=${articleId}&action=edit`);
      await expect(page.locator('body')).not.toContainText('Sorry, you are not allowed');
      await expect(page.locator('body')).toHaveClass(/block-editor-page/);

      const editableDraft = await page.request.get(
        `/wp-json/wp/v2/posts/${articleId}?context=edit`,
        { headers: restHeaders }
      );
      expect(editableDraft.status(), await editableDraft.text()).toBe(200);
      expect((await editableDraft.json()).title.raw).toBe(marker);

      const publish = await page.request.post(`/wp-json/wp/v2/posts/${articleId}`, {
        headers: restHeaders,
        data: { status: 'publish' },
      });
      expect(publish.status(), await publish.text()).toBe(200);
      const published = await publish.json();
      expect(published.status).toBe('publish');

      const publicArticle = await request.get(published.link);
      expect(publicArticle.status()).toBe(200);
      expect(await publicArticle.text()).toContain(marker);
    } finally {
      if (articleId > 0) {
        await page.request.delete(`/wp-json/wp/v2/posts/${articleId}?force=true`, {
          headers: restHeaders,
        });
      }
      await page.request.delete(`/wp-json/wp/v2/media/${image.id}?force=true`, {
        headers: restHeaders,
      });
    }

    await page.goto('/wp-admin/plugins.php');
    await expect(page.locator('body')).toContainText(/not allowed|不允许/i);
    await page.goto('/wp-admin/options-general.php');
    await expect(page.locator('body')).toContainText(/not allowed|不允许/i);
    await page.goto('/wp-admin/admin.php?page=yy-site-settings');
    await expect(page.locator('body')).toContainText(/not allowed|不允许/i);
  });
});
