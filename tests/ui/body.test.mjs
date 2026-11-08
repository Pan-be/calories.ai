import { test } from 'node:test';
import assert from 'node:assert/strict';
import { phone, loggedIn, open } from './lib.mjs';

// 2026-11-08 (Konrad: "w ui powinny być widoczne dane z wagi"): body composition on the
// progress screen (from the first weighing of each day) and the weighing screen (the latest
// weighing). Weighings from past days can't be made through the API, so the list is served
// by a route stub with the shape the API returns.

const body = (fat) => ({ fat_pct: fat, fat_kg: Math.round(76 * fat) / 100, lean_kg: Math.round(76 * (100 - fat)) / 100, water_pct: Math.round(7.35 * (100 - fat)) / 10 });
const weighing = (id, day, time, fat, status = 'ok') => ({
  id, day, measured_at: `${day}T${time}:00+01:00`, weight_kg: 76,
  impedances: status === 'no_frame' ? null : Array(22).fill(status === 'ok' ? 2500 : 0),
  body: status === 'ok' ? body(fat) : null, body_status: status,
});

/** Oldest first, like the API. 11-08: morning 12.4, evening 14.0, then a failed frame. */
const HISTORY = [
  weighing(1, '2026-10-30', '07:10', 12.8),
  weighing(2, '2026-11-01', '07:12', 12.6),
  weighing(3, '2026-11-01', '19:30', 13.9),
  weighing(4, '2026-11-07', '07:05', 12.5),
  weighing(5, '2026-11-08', '07:09', 12.4),
  weighing(6, '2026-11-08', '19:20', 14.0),
  weighing(7, '2026-11-08', '19:25', null, 'no_impedance'),
];

async function withHistory(t, history, options = {}) {
  const page = await phone(t, options);
  await loggedIn(page);
  await page.route('**/api/scale/measurements*', (route) => (route.request().method() === 'GET'
    ? route.fulfill({ json: { measurements: history } })
    : route.continue()));
  return page;
}

const pl = (v) => new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 1 }).format(v);

test('progress shows body composition from the first weighing of the latest day', async (t) => {
  const page = await withHistory(t, HISTORY);
  await open(page, '/progress');
  const card = page.locator('#body-card');
  await card.waitFor();
  const text = await card.innerText();
  assert.match(text, /Skład ciała/);
  // 11-08 morning (12.4), not the evening (14.0) or the failed frame after it.
  assert.match(text, new RegExp(`${pl(12.4)} %`));
  assert.match(text, new RegExp(`${pl(body(12.4).fat_kg)} kg`));
  assert.match(text, new RegExp(`${pl(body(12.4).lean_kg)} kg`));
  assert.match(text, new RegExp(`${pl(body(12.4).water_pct)} %`));
  assert.doesNotMatch(text, new RegExp(`${pl(14)} %`));
  assert.match(text, /8 listopada/);
  assert.deepEqual(page.errors, []);
});

test('progress shows the change against the morning at least 7 days earlier', async (t) => {
  const page = await withHistory(t, HISTORY);
  await open(page, '/progress');
  // 11-08 morning 12.4 against 11-01 morning 12.6 (not 11-01 evening, not 11-07).
  await page.locator('#body-card', { hasText: `−${pl(0.2)} pkt` }).waitFor();
  assert.deepEqual(page.errors, []);
});

test('no change is shown without a weighing 7 or more days back', async (t) => {
  const page = await withHistory(t, HISTORY.filter((m) => m.day >= '2026-11-07'));
  await open(page, '/progress');
  await page.locator('#body-card').waitFor();
  assert.doesNotMatch(await page.locator('#body-card').innerText(), /pkt/);
  assert.deepEqual(page.errors, []);
});

test('progress charts the fat % with one point per day', async (t) => {
  const page = await withHistory(t, HISTORY);
  await open(page, '/progress');
  const chart = page.locator('#body-card svg[aria-label="Wykres tłuszczu"]');
  await chart.waitFor();
  assert.equal(await chart.locator('circle').count(), 4); // 10-30, 11-01, 11-07, 11-08
  assert.deepEqual(page.errors, []);
});

test('progress has no body card without a computed weighing', async (t) => {
  const page = await withHistory(t, [weighing(1, '2026-11-08', '07:00', null, 'not_calibrated')]);
  await open(page, '/progress');
  await page.locator('.topbar h1', { hasText: 'Postępy' }).waitFor();
  await page.locator('.stat-row').waitFor();
  assert.equal(await page.locator('#body-card').count(), 0);
  assert.deepEqual(page.errors, []);
});

test('the weighing screen shows the full values of the latest computed weighing', async (t) => {
  const page = await withHistory(t, HISTORY);
  await page.addInitScript(() => {
    // Web Bluetooth present, so the scale screen renders; nothing connects in this test.
    Object.defineProperty(navigator, 'bluetooth', { value: { requestDevice: () => new Promise(() => {}) } });
  });
  await open(page, '/scale');
  const latest = page.locator('#scale-latest');
  await latest.waitFor();
  const text = await latest.innerText();
  // The latest computed weighing is 11-08 evening (14.0); the failed frame after it has none.
  assert.match(text, new RegExp(`${pl(14)} %`));
  assert.match(text, new RegExp(`${pl(body(14).lean_kg)} kg`));
  assert.match(text, new RegExp(`${pl(body(14).water_pct)} %`));
  assert.deepEqual(page.errors, []);
});

test('a week back across the spring DST change still finds the day exactly 7 days earlier', async (t) => {
  // Review 2026-11-08: a millisecond week is 167 h after the spring change and skipped 2027-03-25.
  const page = await withHistory(t, [
    weighing(1, '2027-03-24', '07:00', 13.0),
    weighing(2, '2027-03-25', '07:00', 12.8),
    weighing(3, '2027-04-01', '07:00', 12.5),
  ], { timezoneId: 'Europe/Warsaw' }); // DST only exists in a real zone; the test browser defaults to UTC
  await open(page, '/progress');
  await page.locator('#body-card', { hasText: `−${pl(0.3)} pkt` }).waitFor();
  assert.deepEqual(page.errors, []);
});

test('a failing scale list leaves the progress screen working and says so', async (t) => {
  const page = await phone(t);
  await loggedIn(page);
  await page.route('**/api/scale/measurements*', (route) => route.fulfill({ status: 500, json: { error: 'boom' } }));
  await open(page, '/progress');
  await page.locator('.stat-row').waitFor();
  await page.locator('.card h2', { hasText: 'Kalorie, ostatnie 14 dni' }).waitFor();
  await page.locator('#body-card', { hasText: 'Nie udało się wczytać' }).waitFor();
  assert.deepEqual(page.errors, []);
});

test('a single day shows the values without a chart or a change', async (t) => {
  const page = await withHistory(t, [weighing(1, '2026-11-08', '07:00', 12.4)]);
  await open(page, '/progress');
  const card = page.locator('#body-card');
  await card.waitFor();
  assert.match(await card.innerText(), new RegExp(`${pl(12.4)} %`));
  assert.equal(await card.locator('svg').count(), 0);
  assert.doesNotMatch(await card.innerText(), /pkt/);
  assert.deepEqual(page.errors, []);
});
