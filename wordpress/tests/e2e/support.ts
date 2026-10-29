import { expect, type APIRequestContext, type Page } from '@playwright/test';

const playgroundAutoLoginCookie = 'playground_auto_login_already_happened=1';

const testPng = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
  'base64'
);

export async function uploadTestImage(
  request: APIRequestContext,
  headers: Record<string, string>,
  filename: string
): Promise<{ id: number; source_url: string }> {
  const response = await request.post('/wp-json/wp/v2/media', {
    headers: {
      ...headers,
      'Content-Disposition': `attachment; filename="${filename}"`,
      'Content-Type': 'image/png',
    },
    data: testPng,
  });
  expect(response.status(), await response.text()).toBe(201);
  return response.json();
}

export async function readAdminRestNonce(page: Page): Promise<string> {
  await page.waitForFunction(() => {
    const adminWindow = window as typeof window & {
      wpApiSettings?: { nonce?: string };
      wp?: { apiFetch?: { nonceMiddleware?: { nonce?: string } } };
    };
    return Boolean(
      adminWindow.wpApiSettings?.nonce
      || adminWindow.wp?.apiFetch?.nonceMiddleware?.nonce
    );
  });

  return page.evaluate(() => {
    const adminWindow = window as typeof window & {
      wpApiSettings?: { nonce?: string };
      wp?: { apiFetch?: { nonceMiddleware?: { nonce?: string } } };
    };
    return adminWindow.wpApiSettings?.nonce
      || adminWindow.wp?.apiFetch?.nonceMiddleware?.nonce
      || '';
  });
}

export async function disablePlaygroundAutoLogin(request: APIRequestContext): Promise<void> {
  await request.get('/', {
    headers: { Cookie: playgroundAutoLoginCookie },
  });
}

export async function disablePlaygroundAutoLoginForPage(page: Page): Promise<void> {
  const url = new URL(process.env.TEST_BASE_URL || 'http://127.0.0.1');
  await page.context().addCookies([{
    name: 'playground_auto_login_already_happened',
    value: '1',
    domain: url.hostname,
    path: '/',
    sameSite: 'Lax',
  }]);
}
