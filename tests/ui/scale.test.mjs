import { test } from 'node:test';
import assert from 'node:assert/strict';
import { phone, loggedIn, open } from './lib.mjs';

// Frames recorded from the 4fizjo B8 on 2026-10-27 (Bluefy test page): 76.80 kg.
const LIVE_ZERO = '33cc11000003800000000000020103009a';
const LIVE_SETTLING = '33cc1100000380001dfb4218020102000b'; // 76.75 kg
const LIVE_STABLE = '33cc1100000380001e004222020102809b'; // 76.80 kg, byte 15 = 0x80
const END = '33cc0a000003820040cf';
const FULL = '33cc76000103810173711e00422202010200000000005c050002a000453370004530000043858000452360004521d000451c4000451b0000434e2000450cf000450a2000'
  + '45366000453300004385c0004528b0004526000043757000451ee000451c0000436120004511e000450f0000435f03030094';
const FFF1 = '0000fff1-0000-1000-8000-00805f9b34fb';
const FFF3 = '0000fff3-0000-1000-8000-00805f9b34fb';

/**
 * A fake navigator.bluetooth: requestDevice() resolves (or rejects with `reject`, as is,
 * like Bluefy does), and window.fakeScale.send(uuid, hex) pushes a packet to the page.
 */
function fakeBluetooth(page, { reject, rejectUndefined = false, hold = false } = {}) {
  return page.addInitScript(({ reject, rejectUndefined, hold }) => {
    let release;
    const held = new Promise((r) => { release = r; });
    const listeners = {};
    const characteristic = (uuid) => ({
      uuid,
      addEventListener: (type, fn) => { listeners[uuid] = fn; },
      startNotifications: async function () { return this; },
    });
    const device = {
      name: 'IF_B8B',
      handlers: [],
      addEventListener(type, fn) { if (type === 'gattserverdisconnected') this.handlers.push(fn); },
      gatt: {
        connected: false,
        async connect() { this.connected = true; window.fakeScale.options.connected = true; return this; },
        disconnect() { this.connected = false; device.handlers.forEach((fn) => fn()); },
        async getPrimaryService(uuid) {
          window.fakeScale.options.service = uuid;
          return { getCharacteristic: async (c) => characteristic(c) };
        },
      },
    };
    window.fakeScale = {
      options: {},
      send(uuid, hex) {
        const bytes = new Uint8Array(hex.match(/../g).map((b) => parseInt(b, 16)));
        listeners[uuid]({ target: { value: new DataView(bytes.buffer) } });
      },
      drop() { device.gatt.disconnect(); },
      connected: () => device.gatt.connected,
      release: () => release(),
    };
    Object.defineProperty(Navigator.prototype, 'bluetooth', {
      configurable: true,
      get: () => ({
        async requestDevice(options) {
          window.fakeScale.options.request = options;
          if (hold) await held;
          if (rejectUndefined) throw undefined;
          if (reject !== undefined) throw reject;
          return device;
        },
      }),
    });
  }, { reject, rejectUndefined, hold });
}

async function scaleScreen(t, options) {
  const page = await phone(t);
  await fakeBluetooth(page, options);
  await loggedIn(page);
  await open(page, '/scale');
  return page;
}

const send = (page, uuid, hex) => page.evaluate(([u, h]) => window.fakeScale.send(u, h), [uuid, hex]);
const measurements = (page) => page.evaluate(() => fetch('/api/scale/measurements', {
  headers: { Accept: 'application/json', Authorization: 'Bearer ' + localStorage.getItem('kalorie.token') },
}).then((r) => r.json()).then((b) => b.measurements));

test('a weighing: live weight, the stable reading is saved once, then the impedance frame', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();

  // Bluefy needs full 128-bit UUIDs, not the 0xfff0 aliases.
  const request = await page.evaluate(() => window.fakeScale.options.request);
  assert.deepEqual(request.optionalServices, ['0000fff0-0000-1000-8000-00805f9b34fb']);
  assert.equal(await page.evaluate(() => window.fakeScale.options.service), '0000fff0-0000-1000-8000-00805f9b34fb');

  await send(page, FFF1, LIVE_ZERO);
  await send(page, FFF1, LIVE_SETTLING);
  await page.locator('#scale-weight', { hasText: '76,75' }).waitFor();
  assert.deepEqual(await measurements(page), []); // nothing saved while settling

  for (let i = 0; i < 5; i++) await send(page, FFF1, LIVE_STABLE); // the scale repeats the stable frame
  await page.locator('#scale-status', { hasText: 'Zapisano 76,8 kg' }).waitFor();
  await send(page, FFF1, END);
  let saved = await measurements(page);
  assert.equal(saved.length, 1);
  assert.equal(saved[0].weight_kg, 76.8);
  assert.equal(saved[0].impedances, null);

  // The 118-byte frame arrives in 20-byte packets, the last one shorter.
  for (const packet of FULL.match(/.{1,40}/g)) await send(page, FFF3, packet);
  await page.locator('#scale-status', { hasText: 'impedancj' }).waitFor();
  saved = await measurements(page);
  assert.equal(saved.length, 1);
  assert.equal(saved[0].impedances.length, 22);

  // The list shows the weighing, and it is the day's weight on the progress screen.
  assert.match(await page.locator('#scale-list').innerText(), /76,8 kg/);
  await open(page, '/progress');
  assert.match(await page.locator('.stat-row').innerText(), /76,8 kg/);
  assert.deepEqual(page.errors, []);
});

test('stepping off and on again in the same connection saves a second weighing', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await send(page, FFF1, LIVE_STABLE);
  await page.locator('#scale-status', { hasText: 'Zapisano' }).waitFor();
  await send(page, FFF1, LIVE_ZERO);
  await send(page, FFF1, LIVE_SETTLING);
  await send(page, FFF1, LIVE_STABLE);
  await page.waitForFunction(() => document.querySelectorAll('#scale-list .list-row').length === 2);
  assert.equal((await measurements(page)).length, 2);
  assert.deepEqual(page.errors, []);
});

test('a wrong weighing is deleted from the list', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await send(page, FFF1, LIVE_STABLE);
  await page.locator('#scale-list .list-row').first().waitFor();

  page.once('dialog', (d) => d.accept());
  await page.click('#scale-list [data-del]');
  await page.locator('#scale-list', { hasText: 'Brak ważeń' }).waitFor();
  assert.deepEqual(await measurements(page), []);
  assert.deepEqual(page.errors, []);
});

test('the scale disconnecting is shown and the user can connect again', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await page.evaluate(() => window.fakeScale.drop());
  await page.locator('#scale-status', { hasText: 'Rozłączono' }).waitFor();
  assert.equal(await page.locator('#scale-connect').isVisible(), true);
  assert.deepEqual(page.errors, []);
});

test('Bluefy rejecting with a plain value shows an error, not "undefined"', async (t) => {
  const page = await scaleScreen(t, { reject: 'Bluetooth adapter not available.' });
  await page.click('#scale-connect');
  const status = page.locator('#scale-status');
  await status.locator('text=Bluetooth adapter not available.').waitFor();
  assert.doesNotMatch(await status.innerText(), /undefined/);
  assert.equal(await page.locator('#scale-connect').isVisible(), true);
  assert.deepEqual(page.errors, []);
});

test('without Web Bluetooth the screen says to open Kalorie in Bluefy', async (t) => {
  const page = await phone(t);
  await page.addInitScript(() => Object.defineProperty(Navigator.prototype, 'bluetooth', { configurable: true, get: () => undefined }));
  await loggedIn(page);
  await open(page, '/scale');
  await page.locator('.screen', { hasText: 'Bluefy' }).waitFor();
  assert.equal(await page.locator('#scale-connect').count(), 0);
  assert.deepEqual(page.errors, []);
});

test('the progress screen links to the weighing screen', async (t) => {
  const page = await phone(t);
  await loggedIn(page);
  await open(page, '/progress');
  await page.click('a[href="#/scale"]');
  await page.locator('.topbar', { hasText: 'Ważenie' }).waitFor();
  assert.deepEqual(page.errors, []);
});

test('leaving the screen disconnects the scale, so the next connect is not blocked', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  assert.equal(await page.evaluate(() => window.fakeScale.connected()), true);
  await page.evaluate(() => { location.hash = '/progress'; });
  await page.locator('.topbar', { hasText: 'Postępy' }).waitFor();
  assert.equal(await page.evaluate(() => window.fakeScale.connected()), false);
  assert.deepEqual(page.errors, []);
});

test('a scale picked after leaving the screen is disconnected right away', async (t) => {
  const page = await scaleScreen(t, { hold: true });
  await page.click('#scale-connect');
  await page.evaluate(() => { location.hash = '/progress'; });
  await page.locator('.topbar', { hasText: 'Postępy' }).waitFor();
  await page.evaluate(() => window.fakeScale.release());
  await page.waitForFunction(() => window.fakeScale.options.connected === true);
  await page.waitForFunction(() => window.fakeScale.connected() === false);
  assert.deepEqual(page.errors, []);
});

test('after a dropped link, a new connection saves a reading that is stable from the first frame', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await send(page, FFF1, LIVE_STABLE);
  await page.locator('#scale-status', { hasText: 'Zapisano' }).waitFor();
  await page.evaluate(() => window.fakeScale.drop());
  await page.locator('#scale-status', { hasText: 'Rozłączono' }).waitFor();

  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await send(page, FFF1, LIVE_STABLE);
  await page.waitForFunction(() => document.querySelectorAll('#scale-list .list-row').length === 2);
  assert.equal((await measurements(page)).length, 2);
  assert.deepEqual(page.errors, []);
});

test('a stable flag flickering while still on the scale does not save a second weighing', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await send(page, FFF1, LIVE_STABLE);
  await page.locator('#scale-status', { hasText: 'Zapisano' }).waitFor();
  await send(page, FFF1, LIVE_SETTLING);
  await send(page, FFF1, LIVE_STABLE);
  for (const packet of FULL.match(/.{1,40}/g)) await send(page, FFF3, packet);
  await page.locator('#scale-status', { hasText: 'impedancj' }).waitFor();
  const saved = await measurements(page);
  assert.equal(saved.length, 1);
  assert.equal(saved[0].impedances.length, 22);
  assert.deepEqual(page.errors, []);
});

test('junk before a frame start split right after 33 CC still yields the frame', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  await send(page, FFF1, '010233cc');
  await send(page, FFF1, LIVE_STABLE.slice(4));
  await page.locator('#scale-status', { hasText: 'Zapisano 76,8 kg' }).waitFor();
  assert.deepEqual(page.errors, []);
});

test('an impedance frame with no saved weighing says so', async (t) => {
  const page = await scaleScreen(t);
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'Stań na wadze' }).waitFor();
  for (const packet of FULL.match(/.{1,40}/g)) await send(page, FFF3, packet);
  await page.locator('#scale-status', { hasText: 'bez zapisanej wagi' }).waitFor();
  assert.deepEqual(await measurements(page), []);
  assert.deepEqual(page.errors, []);
});

test('Bluefy rejecting with undefined shows a readable error', async (t) => {
  const page = await scaleScreen(t, { rejectUndefined: true });
  await page.click('#scale-connect');
  await page.locator('#scale-status', { hasText: 'nieznany błąd' }).waitFor();
  assert.doesNotMatch(await page.locator('#scale-status').innerText(), /undefined/);
  assert.deepEqual(page.errors, []);
});
