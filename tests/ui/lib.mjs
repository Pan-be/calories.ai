import { chromium } from 'playwright-core';

export const BASE = process.env.KALORIE_UI_BASE ?? 'http://kalorie-ui:8000';

/** A phone-sized page; page errors are collected so a test can assert there were none. */
export async function phone(t) {
  const browser = await chromium.launch();
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  return page;
}

/** A fresh user with a complete profile, logged in on the page. */
export async function loggedIn(page) {
  await page.goto(BASE + '/');
  await page.evaluate(async () => {
    const call = (method, url, body, token) => fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) },
      body: JSON.stringify(body),
    }).then(async (r) => {
      if (!r.ok) throw new Error(`${method} ${url}: ${r.status} ${await r.text()}`);
      return r.json();
    });
    const { token } = await call('POST', '/api/auth/register', { email: `ui${Date.now()}${Math.random()}@example.com`, password: 'tajnehaslo1' });
    await call('PUT', '/api/me/profile', { sex: 'male', birth_date: '1990-05-01', height_cm: 180, weight_kg: 80, activity: 'moderate', goal: 'lose', timezone: 'Europe/Warsaw' }, token);
    localStorage.setItem('kalorie.token', token);
  });
}

/** Opens a hash route; the app reads the token only on load, hence the reload. */
export async function open(page, route) {
  await page.goto(`${BASE}/#${route}`);
  await page.reload();
}

/** Today in the browser's local time, as the app computes it. */
export const today = (page) => page.evaluate(() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
});
