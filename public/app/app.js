// Kalorie web app: hash routes, plain DOM, no build step.
import { api, getToken, setToken, onLoggedOut, photo, forgetPhotos } from './api.js?v=2';

const app = document.getElementById('app');
const state = { user: null, pending: [] };

// ---------- HTML helpers: interpolations are escaped unless they come from html`` ----------

class Safe { constructor(s) { this.s = s; } toString() { return this.s; } }

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ESC[c]);

function html(strings, ...values) {
  let out = strings[0];
  values.forEach((v, i) => {
    out += render(v) + strings[i + 1];
  });
  return new Safe(out);
}

function render(v) {
  if (v === null || v === undefined || v === false) return '';
  if (v instanceof Safe) return v.s;
  if (Array.isArray(v)) return v.map(render).join('');
  return esc(v);
}

function mount(content) {
  app.innerHTML = render(content);
  window.scrollTo(0, 0);
}

const $ = (sel, root = app) => root.querySelector(sel);
const $$ = (sel, root = app) => [...root.querySelectorAll(sel)];

// ---------- Formatting ----------

const nf0 = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 0 });
const nf1 = new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 1 });
const n0 = (v) => nf0.format(Math.round(v || 0));
const n1 = (v) => nf1.format(v || 0);

function localDay(date = new Date()) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function parseDay(day) {
  const [y, m, d] = day.split('-').map(Number);
  return new Date(y, m - 1, d, 12);
}

function addDays(day, n) {
  const d = parseDay(day);
  d.setDate(d.getDate() + n);
  return localDay(d);
}

function dayTitle(day) {
  const today = localDay();
  if (day === today) return 'Dziś';
  if (day === addDays(today, -1)) return 'Wczoraj';
  return parseDay(day).toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' });
}

const timeOf = (iso) => new Date(iso).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });

// ISO 8601 with the device's offset, e.g. 2026-10-13T19:05:00+02:00.
function isoLocal(date) {
  const off = -date.getTimezoneOffset();
  const sign = off >= 0 ? '+' : '-';
  const pad = (v) => String(Math.floor(Math.abs(v))).padStart(2, '0');
  return `${localDay(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${sign}${pad(off / 60)}:${pad(off % 60)}`;
}

// When a meal added while viewing $day was eaten: now, or that day at the current time.
function eatenAtFor(day) {
  const now = new Date();
  if (day === localDay(now)) return isoLocal(now);
  const d = parseDay(day);
  d.setHours(now.getHours(), now.getMinutes(), 0, 0);
  return isoLocal(d);
}

// ---------- Icons ----------

const icon = {
  plus: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>`,
  back: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>`,
  next: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>`,
  home: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M4 10.5L12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/></svg>`,
  chart: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>`,
  gear: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.6 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>`,
  camera: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>`,
  image: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></svg>`,
  pen: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/></svg>`,
  list: html`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>`,
  flame: '🔥',
};

const MACROS = [
  { key: 'protein_g', label: 'Białko', cls: 'p', color: 'var(--protein)', icon: '🥩' },
  { key: 'carbs_g', label: 'Węglowodany', cls: 'c', color: 'var(--carbs)', icon: '🌾' },
  { key: 'fat_g', label: 'Tłuszcze', cls: 'f', color: 'var(--fat)', icon: '🥑' },
];

function ring(value, goal, size, stroke, color, inner = '') {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const frac = goal > 0 ? Math.min(1, Math.max(0, value / goal)) : 0;
  return html`<div class="ring" style="width:${size}px;height:${size}px">
    <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">
      <circle cx="${size / 2}" cy="${size / 2}" r="${r}" stroke="var(--track)" stroke-width="${stroke}"/>
      <circle cx="${size / 2}" cy="${size / 2}" r="${r}" stroke="${color}" stroke-width="${stroke}"
        stroke-dasharray="${c}" stroke-dashoffset="${c * (1 - frac)}"/>
    </svg><span class="ring-icon">${inner}</span></div>`;
}

const chips = (t) => html`<div class="chips">
  ${MACROS.map((m) => html`<span><i class="dot ${m.cls}"></i>${n0(t[m.key])} g</span>`)}</div>`;

// ---------- Toasts, sheets ----------

let toastTimer;
function toast(message, error = false) {
  document.querySelector('.toast')?.remove();
  const el = document.createElement('div');
  el.className = `toast${error ? ' error' : ''}`;
  el.textContent = message;
  document.body.append(el);
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.remove(), error ? 5000 : 2500);
}

function sheet(content, onMount) {
  closeSheet();
  const back = document.createElement('div');
  back.className = 'sheet-back';
  back.innerHTML = render(html`<div class="sheet" role="dialog" aria-modal="true"><div class="grab"></div>${content}</div>`);
  back.addEventListener('click', (e) => { if (e.target === back) closeSheet(); });
  document.body.append(back);
  onMount?.(back.firstElementChild);
  return back.firstElementChild;
}

function closeSheet() {
  document.querySelector('.sheet-back')?.remove();
}

const errorBox = (msg) => (msg ? html`<div class="error-box" role="alert">${msg}</div>` : '');

// ---------- Layout ----------

function nav(active) {
  const link = (href, key, ic, label) => html`<a href="${href}" class="${active === key ? 'on' : ''}">${ic}<span>${label}</span></a>`;
  return html`<nav class="nav"><div class="inner">
    ${link('#/', 'day', icon.home, 'Dziennik')}
    ${link('#/progress', 'progress', icon.chart, 'Postępy')}
    ${link('#/settings', 'settings', icon.gear, 'Ustawienia')}
  </div></nav>`;
}

// ---------- Router ----------

const routes = [
  [/^\/login$/, () => viewAuth('login')],
  [/^\/register$/, () => viewAuth('register')],
  [/^\/profile$/, () => viewProfile(false)],
  [/^\/welcome$/, () => viewProfile(true)],
  [/^\/progress$/, viewProgress],
  [/^\/settings$/, viewSettings],
  [/^\/meal\/new\/(\d{4}-\d{2}-\d{2})$/, (m) => viewNewMeal(m[1])],
  [/^\/meal\/(\d+)$/, (m) => viewMeal(Number(m[1]))],
  [/^\/day\/(\d{4}-\d{2}-\d{2})$/, (m) => viewDay(m[1])],
  [/^\/?$/, () => viewDay(localDay())],
];

async function route() {
  closeSheet();
  const path = location.hash.replace(/^#/, '') || '/';
  const isAuth = path === '/login' || path === '/register';
  if (!getToken() && !isAuth) return go('/login');
  if (getToken() && isAuth) return go('/');
  if (getToken() && !state.user) {
    try {
      state.user = (await api('GET', '/me')).user;
    } catch (e) {
      if (e.status === 401) return go('/login');
      return mount(html`<div class="screen plain">${errorBox(e.message)}<button class="btn" onclick="location.reload()">Spróbuj ponownie</button></div>`);
    }
  }
  if (state.user && !state.user.profile.complete && path !== '/welcome' && path !== '/settings') {
    return go('/welcome');
  }
  for (const [re, view] of routes) {
    const m = path.match(re);
    if (m) return view(m);
  }
  go('/');
}

function go(path) {
  if (location.hash === `#${path}`) route();
  else location.hash = path;
}

// Today lives on the home screen, so a #/day/<date> left open never turns into "some old day" later.
function goDay(day) {
  go(day === localDay() ? '/' : `/day/${day}`);
}

onLoggedOut(() => {
  state.user = null;
  forgetPhotos();
  go('/login');
});

window.addEventListener('hashchange', route);

// ---------- Auth ----------

function viewAuth(mode) {
  const register = mode === 'register';
  mount(html`<div class="screen plain auth">
    <img class="logo" src="/app/icon.svg" alt="">
    <h1>${register ? 'Załóż konto' : 'Kalorie'}</h1>
    <p class="lead">${register ? 'Zrób zdjęcie posiłku, a resztę policzymy za Ciebie.' : 'Zaloguj się, żeby liczyć kalorie ze zdjęć.'}</p>
    <form id="f" novalidate>
      <div id="err"></div>
      <label class="field"><span>E-mail</span><input class="input" type="email" name="email" autocomplete="email" required></label>
      <label class="field"><span>Hasło${register ? ' (min. 8 znaków)' : ''}</span>
        <input class="input" type="password" name="password" autocomplete="${register ? 'new-password' : 'current-password'}" required></label>
      <button class="btn" type="submit">${register ? 'Załóż konto' : 'Zaloguj się'}</button>
    </form>
    <p class="hint" style="text-align:center;margin-top:18px">
      ${register ? html`Masz już konto? <a href="#/login">Zaloguj się</a>` : html`Nie masz konta? <a href="#/register">Załóż je</a>`}
    </p>
  </div>`);

  $('#f').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button[type=submit]');
    btn.disabled = true;
    const data = Object.fromEntries(new FormData(e.target));
    if (register) data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    try {
      const res = await api('POST', register ? '/auth/register' : '/auth/login', { json: data });
      setToken(res.token);
      state.user = res.user;
      go(res.user.profile.complete ? '/' : '/welcome');
    } catch (err) {
      $('#err').innerHTML = render(errorBox(err.message));
      btn.disabled = false;
    }
  });
}

// ---------- Profile (onboarding and settings) ----------

const ACTIVITY = [
  ['sedentary', 'Siedzący', 'Praca przy biurku, mało ruchu'],
  ['light', 'Lekko aktywny', 'Spacery, trening 1–3 razy w tygodniu'],
  ['moderate', 'Aktywny', 'Trening 3–5 razy w tygodniu'],
  ['active', 'Bardzo aktywny', 'Trening 6–7 razy w tygodniu'],
  ['very_active', 'Ekstremalnie aktywny', 'Praca fizyczna i codzienny trening'],
];
const GOALS = [['lose', 'Schudnąć'], ['maintain', 'Utrzymać wagę'], ['gain', 'Przytyć']];

function viewProfile(welcome) {
  const p = state.user.profile;
  const radio = (name, value, current, label) =>
    html`<label><input type="radio" name="${name}" value="${value}" ${current === value ? 'checked' : ''}>${label}</label>`;

  mount(html`<div class="screen plain">
    <div class="topbar">
      ${welcome ? html`<h1>Witaj!</h1>` : html`<a class="icon-btn" href="#/settings" aria-label="Wróć">${icon.back}</a><div class="title-sm">Profil</div><span style="width:40px"></span>`}
    </div>
    ${welcome ? html`<p class="hint" style="margin-top:0">Kilka informacji o Tobie, żeby policzyć dzienny cel kalorii i makroskładników.</p>` : ''}
    <form id="f" novalidate>
      <div id="err"></div>
      <div class="field"><span>Płeć</span><div class="seg">
        ${radio('sex', 'male', p.sex, 'Mężczyzna')}${radio('sex', 'female', p.sex, 'Kobieta')}</div></div>
      <label class="field"><span>Data urodzenia</span><input class="input" type="date" name="birth_date" value="${p.birth_date || ''}" required></label>
      <div class="grid2">
        <label class="field"><span>Wzrost (cm)</span><input class="input" type="number" inputmode="decimal" name="height_cm" value="${p.height_cm ?? ''}" step="any" min="100" max="250" required></label>
        <label class="field"><span>Waga (kg)</span><input class="input" type="number" inputmode="decimal" step="any" name="weight_kg" value="${p.weight_kg ?? ''}" min="25" max="400" required></label>
      </div>
      <div class="field"><span>Aktywność</span><div class="options">
        ${ACTIVITY.map(([v, t, d]) => html`<label class="option"><input type="radio" name="activity" value="${v}" ${p.activity === v ? 'checked' : ''}>
          <div><div class="t">${t}</div><div class="d">${d}</div></div></label>`)}
      </div></div>
      <div class="field"><span>Cel</span><div class="seg">${GOALS.map(([v, t]) => radio('goal', v, p.goal, t))}</div></div>
      <label class="field"><span>Waga docelowa (kg, opcjonalnie)</span><input class="input" type="number" inputmode="decimal" step="any" name="target_weight_kg" value="${p.target_weight_kg ?? ''}" min="25" max="400"></label>
      ${!welcome && state.user.goals.manual ? html`<label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" name="reset_goals" value="true"> <span style="margin:0">Przelicz cele od nowa (teraz ustawione ręcznie)</span></label>` : ''}
      <button class="btn" type="submit">${welcome ? 'Policz mój cel' : 'Zapisz'}</button>
    </form>
  </div>`);

  $('#f').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('button[type=submit]');
    btn.disabled = true;
    const data = Object.fromEntries(new FormData(e.target));
    if (!data.target_weight_kg) delete data.target_weight_kg;
    data.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    try {
      const res = await api('PUT', '/me/profile', { json: data });
      state.user = res.user;
      if (welcome) showPlan(res.user.goals);
      else { toast('Zapisano profil'); go('/settings'); }
    } catch (err) {
      $('#err').innerHTML = render(errorBox(err.message));
      window.scrollTo(0, 0);
      btn.disabled = false;
    }
  });
}

function showPlan(goals) {
  mount(html`<div class="screen plain">
    <div class="topbar"><h1>Twój plan</h1></div>
    <p class="hint" style="margin-top:0">Dzienny cel policzony z Twojego profilu. Możesz go zmienić w ustawieniach.</p>
    <div class="card hero"><div><div class="big">${n0(goals.kcal)}</div><div class="sub">kcal dziennie</div></div>
      ${ring(1, 1, 96, 10, 'var(--kcal)', icon.flame)}</div>
    <div class="macros">${MACROS.map((m) => html`<div class="card macro"><div class="val">${n0(goals[m.key])} g</div><div class="lbl">${m.label}</div>
      ${ring(1, 1, 52, 6, m.color, m.icon)}</div>`)}</div>
    <a class="btn" href="#/">Zaczynamy</a>
  </div>`);
}

// ---------- Day view ----------

async function viewDay(day) {
  if (day > localDay()) return go('/');
  mount(html`<div class="screen"><div class="center-load"><div class="spinner dark"></div></div></div>${nav('day')}`);
  let data;
  let week;
  try {
    [data, week] = await Promise.all([
      api('GET', `/days/${day}`),
      api('GET', `/summary?days=7&end=${addDays(day, Math.min(3, daysBetween(day, localDay())))}`),
    ]);
  } catch (e) {
    return mount(html`<div class="screen">${errorBox(e.message)}<button class="btn" onclick="location.reload()">Odśwież</button></div>${nav('day')}`);
  }
  renderDay(day, data, week);
}

function daysBetween(a, b) {
  return Math.round((parseDay(b) - parseDay(a)) / 86400000);
}

function renderDay(day, data, week) {
  const { goals, totals, remaining } = data;
  const over = remaining.kcal < 0;
  const pending = state.pending.filter((p) => p.day === day);
  const today = localDay();
  const weekStrip = week.days.map((d) => {
    const cls = [d.day === day && 'sel', d.meals > 0 && 'has', d.kcal > week.goals.kcal * 1.05 && 'over', d.day > today && 'future'].filter(Boolean).join(' ');
    const wd = parseDay(d.day).toLocaleDateString('pl-PL', { weekday: 'short' }).replace('.', '');
    return html`<a class="${cls}" href="#/day/${d.day}">${wd}<b>${parseDay(d.day).getDate()}</b></a>`;
  });

  mount(html`<div class="screen">
    <div class="topbar">
      <a class="icon-btn" href="#/day/${addDays(day, -1)}" aria-label="Poprzedni dzień">${icon.back}</a>
      <div class="title-sm">${dayTitle(day)}</div>
      ${day < today ? html`<a class="icon-btn" href="#/day/${addDays(day, 1)}" aria-label="Następny dzień">${icon.next}</a>` : html`<span style="width:40px"></span>`}
    </div>
    <div class="week">${weekStrip}</div>

    <div class="card hero">
      <div>
        <div class="big">${n0(Math.abs(remaining.kcal))}</div>
        <div class="sub">${over ? html`kcal <strong>ponad cel</strong>` : 'kcal zostało'}</div>
        <div class="sub"><strong>${n0(totals.kcal)}</strong> / ${n0(goals.kcal)} kcal</div>
      </div>
      ${ring(totals.kcal, goals.kcal, 104, 11, over ? 'var(--bad)' : 'var(--kcal)', icon.flame)}
    </div>

    <div class="macros">
      ${MACROS.map((m) => {
        const left = remaining[m.key];
        return html`<div class="card macro">
          <div><div class="val">${n0(Math.abs(left))} g</div><div class="lbl">${m.label} ${left < 0 ? 'ponad' : 'zostało'}</div></div>
          ${ring(totals[m.key], goals[m.key], 56, 6, m.color, m.icon)}
        </div>`;
      })}
    </div>

    <button class="card workout${data.workout ? '' : ' empty-w'}" id="workout">${workoutLabel(data.workout)}</button>
    ${day === today && remaining.kcal > 50 ? html`<button class="btn secondary" id="suggest">✨ Co zjeść?</button>` : ''}

    <div class="section-title">${day === today ? 'Dzisiejsze posiłki' : 'Posiłki'}</div>
    <div id="meals">
      ${pending.map(pendingCard)}
      ${data.meals.length === 0 && pending.length === 0
        ? html`<div class="card empty"><span class="em">🍽️</span>Nic jeszcze nie dodano.<br>Stuknij <b>+</b> i zrób zdjęcie posiłku.</div>`
        : data.meals.slice().reverse().map(mealCard)}
    </div>
  </div>
  ${nav('day')}
  <button class="fab" id="fab" aria-label="Dodaj posiłek">${icon.plus}</button>`);

  $('#fab').addEventListener('click', () => addSheet(day));
  $('#workout').addEventListener('click', () => workoutSheet(day, data.workout));
  $('#suggest')?.addEventListener('click', () => suggestSheet(day, remaining));
  loadThumbs();
  // Once a day, on the first look at today: ask about training.
  if (day === today && !data.workout && !pending.length && !askedWorkout(today)) workoutSheet(day, null, true);
}

function mealCard(meal) {
  const fallback = { text: '📝', suggestion: '✨' }[meal.source] || '🍽️';
  return html`<a class="card meal" href="#/meal/${meal.id}">
    <div class="thumb" data-photo="${meal.thumb_url || ''}">${meal.thumb_url ? '' : fallback}</div>
    <div class="body">
      <div class="row1"><span class="name">${meal.name}</span><span class="time">${timeOf(meal.eaten_at)}</span></div>
      <div class="kcal">🔥 ${n0(meal.totals.kcal)} kcal</div>
      ${chips(meal.totals)}
    </div></a>`;
}

function pendingCard(p) {
  return html`<div class="card meal pending">
    <div class="thumb" style="${p.preview ? `background-image:url(${p.preview})` : ''}"><div class="spinner"></div></div>
    <div class="body"><div class="name">${p.label}</div>
      <div class="skeleton" style="width:70%"></div><div class="skeleton" style="width:45%"></div></div></div>`;
}

async function loadThumbs(root = app) {
  for (const el of $$('[data-photo]', root)) {
    const url = el.dataset.photo;
    if (!url) continue;
    photo(url).then((blob) => { if (blob) el.style.backgroundImage = `url(${blob})`; });
  }
}

// ---------- Adding meals ----------

function addSheet(viewDay) {
  let day = viewDay;
  const s = sheet(html`<h3>Dodaj posiłek</h3>
    ${day !== localDay() ? html`<div class="day-note" id="day-note"><span>Dodajesz do: <b>${dayTitle(day)}</b></span>
      <button class="btn secondary small" id="to-today">Na dziś</button></div>` : ''}
    <div class="tiles">
      <label class="tile">${icon.camera}Zrób zdjęcie<input type="file" accept="image/*" capture="environment" data-photo-input></label>
      <label class="tile">${icon.image}Z galerii<input type="file" accept="image/*" data-photo-input></label>
      <button class="tile" id="describe">${icon.pen}Opisz posiłek</button>
      <button class="tile" id="manual">${icon.list}Wpisz ręcznie</button>
    </div>
    <p class="hint" style="margin:14px 2px 0">Na zdjęciu najlepiej widać cały talerz z góry. Po analizie możesz poprawić wynik.</p>`);

  $$('[data-photo-input]', s).forEach((input) => input.addEventListener('change', () => {
    const file = input.files?.[0];
    closeSheet();
    if (file) analyzePhoto(file, day);
  }));
  $('#to-today', s)?.addEventListener('click', () => { day = localDay(); $('#day-note', s).remove(); });
  $('#describe', s).addEventListener('click', () => describeSheet(day));
  $('#manual', s).addEventListener('click', () => { closeSheet(); go(`/meal/new/${day}`); });
}

function describeSheet(day) {
  const s = sheet(html`<h3>Opisz posiłek</h3>
    <form id="df"><div id="derr"></div>
      <textarea class="input" name="text" maxlength="2000" required placeholder="np. owsianka na mleku 2% z bananem i łyżką masła orzechowego"></textarea>
      <div class="btn-row"><button class="btn" type="submit">Policz kalorie</button></div></form>`);
  const ta = $('textarea', s);
  setTimeout(() => ta.focus(), 50);
  $('#df', s).addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = ta.value.trim();
    if (!text) return;
    closeSheet();
    await runAnalysis({ day, label: 'Liczę kalorie…', request: () => api('POST', '/meals/describe', { json: { text, eaten_at: eatenAtFor(day) } }) });
  });
}

async function analyzePhoto(file, day) {
  let blob;
  try {
    blob = await shrink(file);
  } catch {
    blob = file; // let the server judge it
  }
  const preview = URL.createObjectURL(blob);
  const form = new FormData();
  form.append('photo', blob, 'meal.jpg');
  form.append('eaten_at', eatenAtFor(day));
  await runAnalysis({ day, label: 'Analizuję zdjęcie…', preview, request: () => api('POST', '/meals/photo', { form }) });
}

async function runAnalysis({ day, label, preview, request }) {
  const job = { id: Math.random(), day, label, preview };
  state.pending.push(job);
  if (location.hash !== `#/day/${day}` && !(day === localDay() && ['', '#', '#/'].includes(location.hash))) go(`/day/${day}`);
  else viewDay(day);
  try {
    const res = await request();
    toast(`Dodano: ${res.meal.name}`);
  } catch (e) {
    toast(e.message, true);
  } finally {
    state.pending = state.pending.filter((p) => p !== job);
    if (preview) setTimeout(() => URL.revokeObjectURL(preview), 1000);
    const onDay = location.hash === `#/day/${day}` || (day === localDay() && ['', '#', '#/'].includes(location.hash));
    if (onDay) viewDay(day);
  }
}

// Downscale on the device: smaller upload, upright orientation, and HEIC becomes JPEG.
async function shrink(file, max = 1600) {
  const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
  const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close?.();
  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('toBlob'))), 'image/jpeg', 0.86));
}

// ---------- Workouts ----------

const INTENSITY = [['light', 'Lekki'], ['moderate', 'Średni'], ['hard', 'Mocny']];

function workoutLabel(w) {
  if (!w) return html`<span class="em">🏋️</span><span class="t">Dodaj dzisiejszy trening</span>`;
  if (w.rest) return html`<span class="em">😴</span><span class="t">Dzień odpoczynku</span>`;
  const level = INTENSITY.find((i) => i[0] === w.intensity)?.[1];
  return html`<span class="em">🏋️</span><span class="t">${w.description}</span>${level ? html`<span class="tag">${level}</span>` : ''}`;
}

// Whether today's training question was already asked (answered or skipped) on this device.
const ASKED_KEY = 'kalorie.workoutAsked';
function askedWorkout(day) {
  try { return localStorage.getItem(ASKED_KEY) === day || state.askedWorkout === day; } catch { return state.askedWorkout === day; }
}
function markAskedWorkout(day) {
  state.askedWorkout = day;
  try { localStorage.setItem(ASKED_KEY, day); } catch { /* remembered for this session only */ }
}

function workoutSheet(day, current, prompt = false) {
  if (prompt) markAskedWorkout(day);
  const s = sheet(html`<h3>${prompt ? 'Trenujesz dziś?' : `Trening: ${dayTitle(day).toLowerCase()}`}</h3>
    ${prompt ? html`<p class="hint" style="margin-top:-6px">Krótki opis wystarczy. Pomaga w podpowiedziach posiłków, a z czasem w dopasowaniu dziennego celu.</p>` : ''}
    <form id="wkf" novalidate>
      <div class="picks" id="picks"></div>
      <textarea class="input" name="description" maxlength="500" placeholder="np. siłownia: nogi, 60 min; bieganie 5 km">${current && !current.rest ? current.description : ''}</textarea>
      <div class="field" style="margin-top:12px"><span>Intensywność (opcjonalnie)</span><div class="seg">
        ${INTENSITY.map(([v, t]) => html`<label><input type="radio" name="intensity" value="${v}" ${current?.intensity === v ? 'checked' : ''}>${t}</label>`)}</div></div>
      <button class="btn" type="submit">Zapisz trening</button>
      <div class="btn-row"><button class="btn secondary" type="button" id="rest">😴 Dziś odpoczynek</button></div>
      ${prompt ? html`<button class="btn danger" type="button" id="skip" style="color:var(--muted)">Pomiń</button>` : ''}
      ${current ? html`<button class="btn danger" type="button" id="wdel">Usuń wpis</button>` : ''}
    </form>`);

  const f = $('#wkf', s);
  const ta = f.elements.description;
  api('GET', '/workouts?days=1').then((res) => {
    const box = $('#picks', s);
    if (!box || !res.frequent.length) return;
    box.innerHTML = render(res.frequent.map((d) => html`<button type="button" class="pick">${d}</button>`));
    $$('.pick', box).forEach((b) => b.addEventListener('click', () => { ta.value = b.textContent; ta.focus(); }));
  }).catch(() => {});

  const done = async (request, message) => {
    try {
      await request();
      closeSheet();
      toast(message);
      viewDay(day);
    } catch (e) { toast(e.message, true); }
  };
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    const description = ta.value.trim();
    if (!description) { ta.focus(); return toast('Opisz trening albo wybierz „Dziś odpoczynek”.', true); }
    const intensity = f.elements.intensity.value || undefined;
    done(() => api('PUT', `/workouts/${day}`, { json: { description, intensity } }), 'Zapisano trening');
  });
  $('#rest', s).addEventListener('click', () => done(() => api('PUT', `/workouts/${day}`, { json: { rest: true } }), 'Zapisano dzień odpoczynku'));
  $('#skip', s)?.addEventListener('click', closeSheet);
  $('#wdel', s)?.addEventListener('click', () => done(() => api('DELETE', `/workouts/${day}`), 'Usunięto wpis'));
}

// ---------- Meal suggestions ----------

// "1 kubek · 150 g", without repeating the grams when the portion already says them.
function portionText(it) {
  const grams = it.grams ? `${n0(it.grams)} g` : '';
  return [it.portion, it.portion?.includes(grams) ? '' : grams].filter(Boolean).join(' · ');
}

function suggestSheet(day, remaining) {
  const left = (k) => n0(Math.max(0, remaining[k]));
  const s = sheet(html`<h3>✨ Co zjeść?</h3>
    <div class="left-box">Zostało <b>${left('kcal')} kcal</b>
      <div class="chips">${MACROS.map((m) => html`<span><i class="dot ${m.cls}"></i>${m.label} ${left(m.key)} g</span>`)}</div></div>
    <form id="sgf">
      <input class="input" name="note" maxlength="300" placeholder="Co masz pod ręką? (opcjonalnie)">
      <div class="btn-row"><button class="btn" type="submit">Podpowiedz</button></div>
    </form>
    <div id="sg"></div>`);

  const f = $('#sgf', s);
  const btn = $('button', f);
  let suggestions = [];
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    btn.disabled = true;
    $('#sg', s).innerHTML = render(html`<div class="center-load" style="padding:28px 0;min-height:0"><div class="spinner dark"></div><p class="hint">Układam propozycje…</p></div>`);
    try {
      const res = await api('POST', '/suggestions', { json: { note: f.elements.note.value.trim() || undefined } });
      suggestions = res.suggestions;
      $('#sg', s).innerHTML = render(html`${suggestions.map((sg, i) => html`<div class="card sugg">
          <div class="row1"><span class="name">${sg.name}</span><span class="kcal">🔥 ${n0(sg.totals.kcal)} kcal</span></div>
          ${sg.why ? html`<div class="why">${sg.why}</div>` : ''}
          ${chips(sg.totals)}
          <details><summary>Składniki</summary>${sg.items.map((it) => html`<div class="list-row"><span>${it.name}<span class="hint"> · ${portionText(it)}</span></span><span>${n0(it.kcal)} kcal</span></div>`)}</details>
          <button class="btn small" type="button" data-pick="${i}">Zjem to</button>
        </div>`)}
        ${res.notes ? html`<p class="hint">${res.notes}</p>` : ''}`);
      btn.textContent = 'Inne propozycje';
      $$('[data-pick]', s).forEach((b) => b.addEventListener('click', () => pick(suggestions[+b.dataset.pick], b)));
    } catch (err) {
      $('#sg', s).innerHTML = render(errorBox(err.message));
    } finally {
      btn.disabled = false;
    }
  });

  async function pick(sg, b) {
    b.disabled = true;
    try {
      await api('POST', '/meals', { json: { name: sg.name, items: sg.items, source: 'suggestion', eaten_at: eatenAtFor(day) } });
      closeSheet();
      toast(`Dodano: ${sg.name}`);
      viewDay(day);
    } catch (e) {
      toast(e.message, true);
      b.disabled = false;
    }
  }
}

// ---------- Meal detail / editor ----------

function totalsOf(items) {
  return items.reduce((t, i) => {
    t.kcal += +i.kcal || 0;
    t.protein_g += +i.protein_g || 0;
    t.carbs_g += +i.carbs_g || 0;
    t.fat_g += +i.fat_g || 0;
    return t;
  }, { kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 });
}

function healthColor(score) {
  if (score >= 7) return 'var(--good)';
  if (score >= 4) return 'var(--carbs)';
  return 'var(--bad)';
}

async function viewMeal(id) {
  mount(html`<div class="screen plain"><div class="center-load"><div class="spinner dark"></div></div></div>`);
  let meal;
  try {
    meal = (await api('GET', `/meals/${id}`)).meal;
  } catch (e) {
    return mount(html`<div class="screen plain">${errorBox(e.message)}<a class="btn" href="#/">Wróć</a></div>`);
  }
  editor(meal);
}

function viewNewMeal(day) {
  editor({ id: null, name: '', eaten_at: eatenAtFor(day), day, source: 'manual', items: [], totals: totalsOf([]) });
}

function editor(meal) {
  const draft = { name: meal.name, eaten_at: meal.eaten_at, items: meal.items.map((i) => ({ ...i })) };
  let dirty = meal.id === null;
  const backHref = `#/day/${meal.day}`;

  const paint = () => {
    const t = totalsOf(draft.items);
    const top = html`<div class="topbar">
      <a class="icon-btn" href="${backHref}" aria-label="Wróć">${icon.back}</a>
      <span></span>
      ${meal.id ? html`<button class="icon-btn" id="more" aria-label="Więcej">⋯</button>` : html`<span style="width:40px"></span>`}</div>`;
    const local = draft.eaten_at.slice(0, 16);

    mount(html`<div class="screen plain">
      ${meal.photo_url ? html`<div class="photo-hero" data-photo="${meal.photo_url}">${top}</div>` : top}
      <div class="${meal.photo_url ? 'detail' : ''}">
        <div class="card">
          <label class="field when"><span>Data i godzina</span><input class="input" type="datetime-local" id="when" value="${local}"></label>
          <textarea class="name-input" id="name" rows="1" placeholder="Nazwa posiłku" maxlength="200" aria-label="Nazwa posiłku">${draft.name}</textarea>
          <div class="totals">
            <div class="kc">${ring(1, 1, 44, 5, 'var(--kcal)', '🔥')}<div><div class="hint">Kalorie</div><div class="v">${n0(t.kcal)}</div></div></div>
            <div class="three">${MACROS.map((m) => html`<div><small><i class="dot ${m.cls}"></i>${m.label}</small><b>${n0(t[m.key])} g</b></div>`)}</div>
          </div>
          ${meal.health_score ? html`<div class="health"><span>💚</span><div style="flex:1"><div class="hint" style="font-size:13px">Ocena zdrowotna</div>
            <div class="bar"><i style="width:${meal.health_score * 10}%;background:${healthColor(meal.health_score)}"></i></div></div><b>${meal.health_score}/10</b></div>` : ''}
          ${meal.notes ? html`<div class="notes">${meal.notes}${meal.confidence ? html` (pewność ${Math.round(meal.confidence * 100)}%)` : ''}</div>` : ''}
        </div>

        <div class="card">
          <h2>Składniki</h2>
          ${draft.items.length === 0 ? html`<p class="hint" style="margin:0 0 8px">Dodaj pierwszy składnik.</p>` : ''}
          ${draft.items.map((i, idx) => html`<div class="item" data-idx="${idx}">
            <div><div class="n">${i.name}</div><div class="s">${[i.portion, i.grams ? `${n0(i.grams)} g` : ''].filter(Boolean).join(' · ')}</div></div>
            <div class="k">${n0(i.kcal)} kcal<div class="s">B ${n0(i.protein_g)} · W ${n0(i.carbs_g)} · T ${n0(i.fat_g)}</div></div></div>`)}
          <button class="btn secondary small" id="add-item" style="margin-top:10px">${icon.plus} Dodaj składnik</button>
        </div>

        ${meal.id && meal.source !== 'manual' ? html`<div class="card"><h2>Coś się nie zgadza?</h2>
          <form id="fix"><textarea class="input" name="text" maxlength="1000" placeholder="np. to były 3 jajka, a nie 2; bez sosu"></textarea>
          <div class="btn-row"><button class="btn secondary" type="submit">✨ Popraw z AI</button></div></form></div>` : ''}

        <div class="btn-row"><button class="btn" id="save" ${dirty ? '' : 'disabled'}>${meal.id ? 'Zapisz zmiany' : 'Dodaj posiłek'}</button></div>
      </div>
    </div>`);

    loadThumbs();
    const nameEl = $('#name');
    const fit = () => { nameEl.style.height = 'auto'; nameEl.style.height = `${nameEl.scrollHeight}px`; };
    fit();
    nameEl.addEventListener('input', () => { draft.name = nameEl.value.replace(/\n/g, ' '); fit(); markDirty(); });
    nameEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    $('#when').addEventListener('change', (e) => {
      if (!e.target.value) return;
      draft.eaten_at = isoLocal(new Date(e.target.value));
      markDirty();
    });
    $$('.item').forEach((el) => el.addEventListener('click', () => itemSheet(draft.items[+el.dataset.idx], (item) => {
      if (item === null) draft.items.splice(+el.dataset.idx, 1);
      else draft.items[+el.dataset.idx] = item;
      dirty = true;
      paint();
    })));
    $('#add-item').addEventListener('click', () => itemSheet(null, (item) => {
      if (item) { draft.items.push(item); dirty = true; paint(); }
    }));
    $('#save').addEventListener('click', save);
    $('#fix')?.addEventListener('submit', fix);
    $('#more')?.addEventListener('click', moreSheet);
  };

  const markDirty = () => { dirty = true; $('#save').disabled = false; };

  async function save() {
    if (!draft.name.trim()) return toast('Podaj nazwę posiłku.', true);
    if (draft.items.length === 0) return toast('Dodaj co najmniej jeden składnik.', true);
    $('#save').disabled = true;
    try {
      const body = { name: draft.name, eaten_at: draft.eaten_at, items: draft.items };
      const res = meal.id ? await api('PATCH', `/meals/${meal.id}`, { json: body }) : await api('POST', '/meals', { json: body });
      toast(meal.id ? 'Zapisano' : 'Dodano posiłek');
      goDay(res.meal.day);
    } catch (e) {
      toast(e.message, true);
      $('#save').disabled = false;
    }
  }

  async function fix(e) {
    e.preventDefault();
    const text = new FormData(e.target).get('text').trim();
    if (!text) return;
    const btn = $('#fix button');
    btn.disabled = true;
    btn.innerHTML = render(html`<div class="spinner dark" style="width:20px;height:20px"></div> Poprawiam…`);
    try {
      if (dirty) await api('PATCH', `/meals/${meal.id}`, { json: { name: draft.name, eaten_at: draft.eaten_at, items: draft.items } });
      const res = await api('POST', `/meals/${meal.id}/fix`, { json: { text } });
      toast('Poprawiono');
      editor(res.meal);
    } catch (err) {
      toast(err.message, true);
      btn.disabled = false;
      btn.textContent = '✨ Popraw z AI';
    }
  }

  function moreSheet() {
    const s = sheet(html`<h3>${meal.name}</h3><div class="options">
      <button class="btn secondary" id="again">Dodaj ponownie dziś</button>
      <button class="btn danger" id="del">Usuń posiłek</button></div>`);
    $('#again', s).addEventListener('click', async () => {
      try {
        await api('POST', `/meals/${meal.id}/copy`, { json: { eaten_at: isoLocal(new Date()) } });
        closeSheet();
        toast('Dodano do dzisiejszych posiłków');
        go('/');
      } catch (e) { toast(e.message, true); }
    });
    $('#del', s).addEventListener('click', async () => {
      if (!confirm('Usunąć ten posiłek?')) return;
      try {
        await api('DELETE', `/meals/${meal.id}`);
        closeSheet();
        toast('Usunięto');
        goDay(meal.day);
      } catch (e) { toast(e.message, true); }
    });
  }

  paint();
}

// One ingredient: changing grams scales the nutrition values, which can also be edited directly.
function itemSheet(item, done) {
  const base = item ? { ...item } : { name: '', portion: '', grams: 100, kcal: 0, protein_g: 0, carbs_g: 0, fat_g: 0 };
  const per = base.grams > 0 ? { kcal: base.kcal / base.grams, protein_g: base.protein_g / base.grams, carbs_g: base.carbs_g / base.grams, fat_g: base.fat_g / base.grams } : null;
  const num = (name, label, value) => html`<label class="field"><span>${label}</span>
    <input class="input" type="number" inputmode="decimal" step="any" min="0" name="${name}" value="${Math.round(value * 10) / 10}"></label>`;

  const s = sheet(html`<h3>${item ? 'Składnik' : 'Nowy składnik'}</h3>
    <form id="itf" novalidate>
      <label class="field"><span>Nazwa</span><input class="input" name="name" value="${base.name}" maxlength="200" required></label>
      <label class="field"><span>Porcja (opis)</span><input class="input" name="portion" value="${base.portion}" maxlength="100" placeholder="np. 1 kromka"></label>
      <div class="field"><span>Gramatura (g)</span><div class="stepper">
        <button type="button" data-step="-10">−</button>
        <input class="input" type="number" inputmode="decimal" min="0" step="any" name="grams" value="${Math.round(base.grams)}">
        <button type="button" data-step="10">+</button></div></div>
      <div class="grid2">${num('kcal', 'Kalorie', base.kcal)}${num('protein_g', 'Białko (g)', base.protein_g)}
        ${num('carbs_g', 'Węglowodany (g)', base.carbs_g)}${num('fat_g', 'Tłuszcze (g)', base.fat_g)}</div>
      ${per ? html`<p class="hint" style="margin-top:0">Zmiana gramatury przelicza kalorie i makro proporcjonalnie.</p>` : ''}
      <button class="btn" type="submit">Gotowe</button>
      ${item ? html`<button class="btn danger" type="button" id="rm" style="margin-top:6px">Usuń składnik</button>` : ''}
    </form>`);

  const f = $('#itf', s);
  const grams = f.elements.grams;
  const rescale = () => {
    if (!per) return;
    const g = Math.max(0, +grams.value || 0);
    for (const k of ['kcal', 'protein_g', 'carbs_g', 'fat_g']) f.elements[k].value = Math.round(per[k] * g * 10) / 10;
  };
  grams.addEventListener('input', rescale);
  $$('[data-step]', s).forEach((b) => b.addEventListener('click', () => {
    grams.value = Math.max(0, (+grams.value || 0) + +b.dataset.step);
    rescale();
  }));
  $('#rm', s)?.addEventListener('click', () => { closeSheet(); done(null); });
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(f));
    if (!d.name.trim()) return;
    const v = (k) => Math.max(0, +String(d[k]).replace(',', '.') || 0);
    closeSheet();
    done({ name: d.name.trim(), portion: d.portion.trim(), grams: v('grams'), kcal: v('kcal'), protein_g: v('protein_g'), carbs_g: v('carbs_g'), fat_g: v('fat_g') });
  });
}

// ---------- Progress ----------

async function viewProgress() {
  mount(html`<div class="screen"><div class="center-load"><div class="spinner dark"></div></div></div>${nav('progress')}`);
  let weights;
  let summary;
  try {
    [weights, summary] = await Promise.all([api('GET', '/weights?days=180'), api('GET', '/summary?days=14')]);
  } catch (e) {
    return mount(html`<div class="screen">${errorBox(e.message)}</div>${nav('progress')}`);
  }
  const entries = weights.entries;
  const last = entries[entries.length - 1];
  const first = entries[0];
  const logged = summary.days.filter((d) => d.meals > 0);
  const avg = logged.length ? logged.reduce((s, d) => s + d.kcal, 0) / logged.length : 0;

  mount(html`<div class="screen">
    <div class="topbar"><h1>Postępy</h1></div>
    <div class="stat-row">
      <div class="card"><div class="v">${last ? `${n1(last.weight_kg)} kg` : '–'}</div><div class="l">Aktualna waga</div></div>
      <div class="card"><div class="v">${weights.target_weight_kg ? `${n1(weights.target_weight_kg)} kg` : '–'}</div><div class="l">Cel</div></div>
    </div>
    <div class="card"><h2>Waga ${first && last && first !== last ? html`<span class="hint" style="font-weight:400">(${last.weight_kg - first.weight_kg > 0 ? '+' : ''}${n1(last.weight_kg - first.weight_kg)} kg)</span>` : ''}</h2>
      ${entries.length >= 2 ? weightChart(entries, weights.target_weight_kg) : html`<p class="hint" style="margin:0 0 12px">Wpisuj wagę regularnie, a pojawi się tu wykres.</p>`}
      <form id="wf" style="display:flex;gap:10px;margin-top:12px">
        <input class="input" type="number" inputmode="decimal" step="any" min="25" max="400" name="weight_kg" placeholder="Dzisiejsza waga (kg)" required>
        <button class="btn small" type="submit" style="min-height:50px">Zapisz</button></form>
    </div>
    <div class="card"><h2>Kalorie, ostatnie 14 dni</h2>
      ${kcalChart(summary.days, summary.goals.kcal)}
      <p class="hint" style="margin:10px 0 0">Średnio ${n0(avg)} kcal w dni z wpisami (${logged.length}/14), cel ${n0(summary.goals.kcal)} kcal.</p></div>
    ${entries.length ? html`<div class="card"><h2>Ostatnie pomiary</h2>
      ${entries.slice(-10).reverse().map((e) => html`<div class="list-row"><span>${parseDay(e.day).toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' })}</span>
        <span><b>${n1(e.weight_kg)} kg</b> <button class="link-btn" data-del="${e.day}" aria-label="Usuń">✕</button></span></div>`)}</div>` : ''}
  </div>${nav('progress')}`);

  $('#wf').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const res = await api('POST', '/weights', { json: { weight_kg: new FormData(e.target).get('weight_kg') } });
      state.user = res.user;
      toast('Zapisano wagę');
      viewProgress();
    } catch (err) { toast(err.message, true); }
  });
  $$('[data-del]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Usunąć ten pomiar?')) return;
    try {
      await api('DELETE', `/weights/${b.dataset.del}`);
      viewProgress();
    } catch (err) { toast(err.message, true); }
  }));
}

function weightChart(entries, target) {
  const W = 320; const H = 150; const P = 26;
  const values = entries.map((e) => e.weight_kg).concat(target ? [target] : []);
  let min = Math.min(...values); let max = Math.max(...values);
  if (max - min < 2) { min -= 1; max += 1; }
  const t0 = parseDay(entries[0].day).getTime();
  const t1 = Math.max(parseDay(entries[entries.length - 1].day).getTime(), t0 + 86400000);
  const x = (day) => P + ((parseDay(day).getTime() - t0) / (t1 - t0)) * (W - P - 8);
  const y = (v) => 8 + (1 - (v - min) / (max - min)) * (H - 30);
  const path = entries.map((e, i) => `${i ? 'L' : 'M'}${x(e.day).toFixed(1)},${y(e.weight_kg).toFixed(1)}`).join(' ');
  return html`<svg class="chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Wykres wagi">
    ${[max, (min + max) / 2, min].map((v) => html`<text x="0" y="${y(v) + 4}">${n1(v)}</text><line x1="${P}" x2="${W}" y1="${y(v)}" y2="${y(v)}" stroke="var(--line)"/>`)}
    ${target ? html`<line x1="${P}" x2="${W}" y1="${y(target)}" y2="${y(target)}" stroke="var(--good)" stroke-dasharray="4 4"/>` : ''}
    <path d="${path}" fill="none" stroke="var(--text)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
    ${entries.map((e) => html`<circle cx="${x(e.day)}" cy="${y(e.weight_kg)}" r="3" fill="var(--text)"/>`)}
    <text x="${P}" y="${H - 4}">${parseDay(entries[0].day).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' })}</text>
    <text x="${W}" y="${H - 4}" text-anchor="end">${parseDay(entries[entries.length - 1].day).toLocaleDateString('pl-PL', { day: 'numeric', month: 'short' })}</text>
  </svg>`;
}

function kcalChart(days, goal) {
  const W = 320; const H = 140; const bottom = 18;
  const max = Math.max(goal * 1.25, ...days.map((d) => d.kcal), 1);
  const bw = W / days.length;
  const y = (v) => (H - bottom) * (1 - v / max);
  return html`<svg class="chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Kalorie dziennie">
    ${days.map((d, i) => {
      const h = (H - bottom) - y(d.kcal);
      const color = d.kcal > goal * 1.05 ? 'var(--bad)' : 'var(--text)';
      return html`<rect x="${i * bw + bw * 0.2}" y="${y(d.kcal)}" width="${bw * 0.6}" height="${Math.max(0, h)}" rx="3" fill="${color}" opacity="${d.meals ? 1 : 0.2}"/>
        <text x="${i * bw + bw / 2}" y="${H - 4}" text-anchor="middle">${parseDay(d.day).getDate()}</text>`;
    })}
    <line x1="0" x2="${W}" y1="${y(goal)}" y2="${y(goal)}" stroke="var(--good)" stroke-dasharray="4 4"/>
  </svg>`;
}

// ---------- Settings ----------

function viewSettings() {
  const u = state.user;
  const g = u.goals;
  const p = u.profile;
  const actLabel = ACTIVITY.find((a) => a[0] === p.activity)?.[1] || '–';
  const goalLabel = GOALS.find((x) => x[0] === p.goal)?.[1] || '–';
  mount(html`<div class="screen">
    <div class="topbar"><h1>Ustawienia</h1></div>
    <div class="card"><h2>Profil</h2>
      <div class="list-row"><span>Waga</span><b>${p.weight_kg ? `${n1(p.weight_kg)} kg` : '–'}</b></div>
      <div class="list-row"><span>Wzrost</span><b>${p.height_cm ? `${n0(p.height_cm)} cm` : '–'}</b></div>
      <div class="list-row"><span>Aktywność</span><b>${actLabel}</b></div>
      <div class="list-row"><span>Cel</span><b>${goalLabel}</b></div>
      <a class="btn secondary small" href="#/profile" style="margin-top:10px">Zmień profil</a></div>
    <div class="card"><h2>Dzienne cele ${g.manual ? html`<span class="hint" style="font-weight:400">(ręczne)</span>` : html`<span class="hint" style="font-weight:400">(automatyczne)</span>`}</h2>
      <form id="gf"><div class="grid2">
        <label class="field"><span>Kalorie</span><input class="input" type="number" inputmode="numeric" name="kcal" value="${g.kcal}" min="800" max="8000"></label>
        <label class="field"><span>Białko (g)</span><input class="input" type="number" inputmode="numeric" name="protein_g" value="${g.protein_g}" min="0" max="500"></label>
        <label class="field"><span>Węglowodany (g)</span><input class="input" type="number" inputmode="numeric" name="carbs_g" value="${g.carbs_g}" min="0" max="1000"></label>
        <label class="field"><span>Tłuszcze (g)</span><input class="input" type="number" inputmode="numeric" name="fat_g" value="${g.fat_g}" min="0" max="400"></label>
      </div>
      <div class="btn-row"><button class="btn" type="submit">Zapisz cele</button>
        ${g.manual ? html`<button class="btn secondary" type="button" id="auto">Licz automatycznie</button>` : ''}</div></form></div>
    <div class="card"><h2>Konto</h2>
      <div class="list-row"><span>E-mail</span><span class="hint">${u.email}</span></div>
      <form id="pf" style="margin-top:12px">
        <label class="field"><span>Obecne hasło</span><input class="input" type="password" name="current_password" autocomplete="current-password"></label>
        <label class="field"><span>Nowe hasło</span><input class="input" type="password" name="new_password" autocomplete="new-password" minlength="8"></label>
        <button class="btn secondary" type="submit">Zmień hasło</button></form>
      <button class="btn danger" id="logout" style="margin-top:10px">Wyloguj się</button></div>
    <p class="hint" style="text-align:center">Kalorie · analiza zdjęć: Claude</p>
  </div>${nav('settings')}`);

  $('#gf').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      state.user = (await api('PUT', '/me/goals', { json: Object.fromEntries(new FormData(e.target)) })).user;
      toast('Zapisano cele');
      viewSettings();
    } catch (err) { toast(err.message, true); }
  });
  $('#auto')?.addEventListener('click', async () => {
    try {
      state.user = (await api('PUT', '/me/goals', { json: { auto: true } })).user;
      toast('Cele liczone automatycznie');
      viewSettings();
    } catch (err) { toast(err.message, true); }
  });
  $('#pf').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      await api('PUT', '/me/password', { json: Object.fromEntries(new FormData(e.target)) });
      e.target.reset();
      toast('Hasło zmienione');
    } catch (err) { toast(err.message, true); }
  });
  $('#logout').addEventListener('click', async () => {
    try { await api('POST', '/auth/logout', { json: {} }); } catch { /* the token is dropped anyway */ }
    setToken(null);
    state.user = null;
    forgetPhotos();
    go('/login');
  });
}

route();

// Coming back to the app after a while or on another date: show today, also from a day view, so a
// meal added right after resuming lands on today and not on a day left open earlier.
let hiddenAt = 0;
let hiddenDay = localDay();
document.addEventListener('visibilitychange', () => {
  if (document.hidden) { hiddenAt = Date.now(); hiddenDay = localDay(); return; }
  if (state.pending.length) return;
  const stale = Date.now() - hiddenAt > 10 * 60 * 1000 || localDay() !== hiddenDay;
  const onDayView = ['', '#', '#/'].includes(location.hash) || location.hash.startsWith('#/day/');
  if (stale && onDayView) go('/');
});
