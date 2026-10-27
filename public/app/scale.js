// 4fizjo B8 scale over Web Bluetooth (Safari has none; on iOS it runs in the Bluefy browser).
// Frames: 33 CC, length (2 bytes LE), ..., checksum = (sum of the other bytes + 1) & 0xFF.
// FFF1 notifies 17-byte live frames (kg×100 big-endian at bytes 8-9, byte 15 0x80 = stable);
// FFF3 indicates one 118-byte frame with the impedances ~15 s after the weighing, split
// into 20-byte packets.

// Full 128-bit UUIDs: Bluefy doesn't accept the 16-bit number aliases.
const uuid16 = (n) => `0000${n.toString(16)}-0000-1000-8000-00805f9b34fb`;
const SERVICE = uuid16(0xfff0);
const LIVE = uuid16(0xfff1);
const FULL = uuid16(0xfff3);

export const supported = () => Boolean(navigator.bluetooth);

const toHex = (bytes) => Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

function checksumOk(frame) {
  let sum = 1;
  for (let i = 0; i < frame.length - 1; i++) sum += frame[i];
  return (sum & 0xff) === frame[frame.length - 1];
}

// Joins packets into whole frames; bytes before a 33 CC start are dropped.
function framer(onFrame) {
  const buf = [];
  return (bytes) => {
    buf.push(...bytes);
    while (buf.length >= 4) {
      const start = buf.findIndex((b, i) => b === 0x33 && buf[i + 1] === 0xcc);
      if (start < 0) { buf.splice(0, buf.length - (buf[buf.length - 1] === 0x33 ? 1 : 0)); return; }
      if (start > 0) buf.splice(0, start);
      if (buf.length < 4) return; // the length bytes haven't arrived yet
      const len = buf[2] | (buf[3] << 8);
      if (len < 5 || len > 200) { buf.splice(0, 2); continue; } // not a real start, look further
      if (buf.length < len) return;
      const frame = Uint8Array.from(buf.splice(0, len));
      if (checksumOk(frame)) onFrame(frame);
    }
  };
}

// Bluefy can reject with a plain string or object instead of an Error.
export function errorText(e) {
  if (e && typeof e.message === 'string' && e.message) return e.message;
  if (typeof e === 'string' && e) return e;
  return e?.name || 'nieznany błąd';
}

export const cancelled = (e) => e?.name === 'NotFoundError' && /cancel/i.test(e.message || '');

/**
 * Asks for the scale (needs a tap), connects and subscribes. Calls onWeight(kg, stable) for
 * every live frame, onFrame(hex) for the impedance frame, onDisconnect() when the link drops.
 * Returns a function that disconnects.
 */
export async function connect({ onWeight, onFrame, onDisconnect }) {
  const device = await navigator.bluetooth.requestDevice({
    filters: [{ namePrefix: 'IF_B8' }, { services: [SERVICE] }],
    optionalServices: [SERVICE],
  });
  // The page gets the same device and characteristic objects on every connect, so the
  // listeners of an earlier connection are still attached: once closed, they do nothing.
  let closed = false;
  const live = (fn) => (...args) => { if (!closed) fn(...args); };
  device.addEventListener('gattserverdisconnected', live(() => { closed = true; onDisconnect(); }));
  const server = await device.gatt.connect();
  try {
    await subscribeAll(await server.getPrimaryService(SERVICE), { onWeight: live(onWeight), onFrame: live(onFrame) });
    if (!device.gatt.connected) throw new Error('Waga rozłączyła się w trakcie łączenia.');
  } catch (e) {
    closed = true; // the error is what the user should see, not "disconnected"
    if (device.gatt.connected) device.gatt.disconnect(); // the scale takes one connection
    throw e;
  }

  return () => {
    closed = true;
    if (device.gatt.connected) device.gatt.disconnect();
  };
}

async function subscribeAll(service, { onWeight, onFrame }) {

  const subscribe = async (uuid, handle) => {
    const ch = await service.getCharacteristic(uuid);
    const feed = framer(handle);
    ch.addEventListener('characteristicvaluechanged', (e) => {
      const v = e.target.value;
      feed(new Uint8Array(v.buffer, v.byteOffset, v.byteLength));
    });
    await ch.startNotifications();
  };
  await subscribe(LIVE, (f) => {
    if (f.length === 17) onWeight(((f[8] << 8) | f[9]) / 100, f[15] === 0x80);
  });
  await subscribe(FULL, (f) => {
    if (f.length === 118) onFrame(toHex(f));
  });
}
