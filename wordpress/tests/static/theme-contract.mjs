import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..', '..');
const theme = resolve(root, 'wp-content/themes/wuhanyingyuan');

const requiredThemeFiles = [
  'style.css',
  'functions.php',
  'header.php',
  'footer.php',
  'front-page.php',
  'home.php',
  'single.php',
  'page.php',
  'index.php',
  '404.php',
  'inc/seo.php',
  'assets/css/wordpress.css',
  'template-parts/home/hero.php',
  'template-parts/home/platforms.php',
  'template-parts/home/services.php',
  'template-parts/home/advantages.php',
  'template-parts/home/process.php',
  'template-parts/home/cases.php',
  'template-parts/home/latest-news.php',
  'template-parts/home/about.php',
  'template-parts/home/stats.php',
  'template-parts/home/faq.php',
  'template-parts/home/contact.php',
];

for (const file of requiredThemeFiles) {
  assert.ok(existsSync(resolve(theme, file)), `Missing theme file: ${file}`);
}

const readTheme = (file) => readFileSync(resolve(theme, file), 'utf8');
const header = readTheme('header.php');
const footer = readTheme('footer.php');
const frontPage = readTheme('front-page.php');
const seo = readTheme('inc/seo.php');
const about = readTheme('template-parts/home/about.php');
const helpers = readTheme('inc/template-helpers.php');

for (const hook of ['wp_head()', 'wp_body_open()']) {
  assert.match(header, new RegExp(hook.replace(/[()]/g, '\\$&')), `header.php must call ${hook}`);
}
assert.match(header, /yy_render_primary_navigation\(\)/);
assert.match(header, /id="navToggle"/);
assert.match(footer, /wp_footer\(\)/);
assert.match(footer, /id="wechatModal"/);

for (const section of [
  'platforms',
  'services',
  'advantages',
  'process',
  'cases',
  'about',
  'stats',
  'faq',
  'contact',
]) {
  assert.match(frontPage, new RegExp(`get_template_part\\([^\\n]*home/${section}`), `Missing homepage section: ${section}`);
}

assert.match(seo, /rel_canonical/);
assert.match(seo, /application\/ld\+json/);
assert.match(seo, /FAQPage/);
assert.match(seo, /Article/);
assert.match(seo, /JSON_UNESCAPED_UNICODE/);
for (const flag of ['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT']) {
  assert.match(seo, new RegExp(flag), `JSON-LD must escape ${flag}`);
}

assert.match(about, /\$carousel_slides = \[\]/, 'Carousel must collect renderable slides before deciding on fallback');
assert.match(about, /if \(\$carousel_slides !== \[\]\)/, 'Carousel must fall back when all configured records lack images');
assert.match(about, /images\/data-1\.png/, 'Carousel fallback image is required');
assert.match(helpers, /wp_attachment_is_image\(\$image_id\)/, 'Stored image IDs must be checked before rendering');

const css = readTheme('assets/css/wordpress.css');
assert.match(css, /\.page-numbers/);
assert.match(css, /\.wp-block-image/);

console.log('Theme contract passed.');
