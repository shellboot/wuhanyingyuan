import { expect, test } from '@playwright/test';
import { disablePlaygroundAutoLogin, uploadTestImage } from './support';

const baseURL = process.env.TEST_BASE_URL || 'http://127.0.0.1';
const username = process.env.TEST_PUBLISHER_USER || 'publisher';
const appPassword = process.env.TEST_PUBLISHER_APP_PASSWORD || '';
const categoryId = Number(process.env.TEST_NEWS_CATEGORY_ID || 0);
const authHeader = `Basic ${Buffer.from(`${username}:${appPassword}`).toString('base64')}`;

test.describe('publisher REST contract', () => {
  test('anonymous requests cannot create posts', async ({ request }) => {
    await disablePlaygroundAutoLogin(request);
    const response = await request.post(`${baseURL}/wp-json/wp/v2/posts`, {
      data: { title: 'Anonymous attempt', status: 'draft' },
    });
    expect([401, 403]).toContain(response.status());
  });

  test('invalid Application Password cannot create posts', async ({ request }) => {
    await disablePlaygroundAutoLogin(request);
    const invalidHeader = `Basic ${Buffer.from(`${username}:invalid-application-password`).toString('base64')}`;
    const response = await request.post(`${baseURL}/wp-json/wp/v2/posts`, {
      headers: { Authorization: invalidHeader },
      data: { title: 'Invalid application password attempt', status: 'draft' },
    });
    expect([401, 403]).toContain(response.status());
  });

  test('publisher manages media, drafts, visibility, and updates one article', async ({ request }) => {
    test.skip(!appPassword || !categoryId, 'Publisher credentials and category ID are required');
    test.setTimeout(90_000);

    await disablePlaygroundAutoLogin(request);
    const marker = `Publisher contract ${Date.now()}`;
    const image = await uploadTestImage(
      request,
      { Authorization: authHeader },
      `publisher-${Date.now()}.png`
    );
    expect(image.id).toBeGreaterThan(0);

    const create = await request.post(`${baseURL}/wp-json/wp/v2/posts`, {
      headers: { Authorization: authHeader },
      data: {
        title: marker,
        content: `<p>${marker}</p>`,
        status: 'draft',
        categories: [categoryId],
        featured_media: image.id,
      },
    });
    expect(create.status(), await create.text()).toBe(201);
    const article = await create.json();
    expect(article.id).toBeGreaterThan(0);
    expect(article.status).toBe('draft');
    expect(article.featured_media).toBe(image.id);
    expect(article.categories).toContain(categoryId);

    const update = await request.post(`${baseURL}/wp-json/wp/v2/posts/${article.id}`, {
      headers: { Authorization: authHeader },
      data: {
        title: `${marker} updated`,
        excerpt: 'Updated by publisher test.',
        featured_media: image.id,
      },
    });
    expect(update.status(), await update.text()).toBe(200);
    const updatedArticle = await update.json();
    expect(updatedArticle.id).toBe(article.id);

    const search = await request.get(
      `${baseURL}/wp-json/wp/v2/posts?search=${encodeURIComponent(marker)}&status=draft&context=edit`,
      { headers: { Authorization: authHeader } }
    );
    expect(search.status(), await search.text()).toBe(200);
    const matches = await search.json();
    expect(matches.filter((item: { id: number }) => item.id === article.id)).toHaveLength(1);

    const anonymousDraft = await request.get(`${baseURL}/?p=${article.id}`);
    expect(anonymousDraft.status()).toBe(404);

    const settingsAttempt = await request.get(`${baseURL}/wp-json/wp/v2/settings`, {
      headers: { Authorization: authHeader },
    });
    expect([401, 403]).toContain(settingsAttempt.status());

    const pluginsAttempt = await request.get(`${baseURL}/wp-json/wp/v2/plugins`, {
      headers: { Authorization: authHeader },
    });
    expect([401, 403, 404]).toContain(pluginsAttempt.status());

    const publish = await request.post(`${baseURL}/wp-json/wp/v2/posts/${article.id}`, {
      headers: { Authorization: authHeader },
      data: { status: 'publish' },
    });
    expect(publish.status(), await publish.text()).toBe(200);
    const publishedArticle = await publish.json();
    expect(publishedArticle.status).toBe('publish');

    const publicArticle = await request.get(publishedArticle.link);
    expect(publicArticle.status()).toBe(200);
    expect(await publicArticle.text()).toContain(marker);

    // The publisher role intentionally cannot delete posts; return the fixture
    // to draft so repeated test runs do not add public archive entries.
    const unpublish = await request.post(`${baseURL}/wp-json/wp/v2/posts/${article.id}`, {
      headers: { Authorization: authHeader },
      data: { status: 'draft' },
    });
    expect(unpublish.status(), await unpublish.text()).toBe(200);
  });
});
