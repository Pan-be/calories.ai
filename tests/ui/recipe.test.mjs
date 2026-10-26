import { test } from 'node:test';
import assert from 'node:assert/strict';
import { phone, loggedIn, open, today } from './lib.mjs';

// Recipes come from tests/fixtures/recipes.json (bin/ui-test points the backend at it).

async function recipeSearch(t) {
  const page = await phone(t);
  await loggedIn(page);
  const day = await today(page);
  await page.evaluate((d) => localStorage.setItem('kalorie.workoutAsked', d), day); // no training question
  await open(page, `/day/${day}`);
  await page.click('#fab');
  await page.click('#recipe');
  return page;
}

test('a recipe picked by search is logged with the chosen portions', async (t) => {
  const page = await recipeSearch(t);
  await page.fill('#rq', 'owsianka jablk');
  await page.locator('.recipe', { hasText: 'Owsianka z jabłkiem' }).click();
  assert.match(await page.locator('#rsum').innerText(), /400 kcal/);

  await page.locator('#raf .seg label', { hasText: '1½' }).click();
  assert.match(await page.locator('#rsum').innerText(), /600 kcal/);
  await page.click('#raf button[type=submit]');

  const card = page.locator('.card.meal', { hasText: 'Owsianka z jabłkiem i cynamonem' });
  await card.waitFor();
  assert.match(await card.innerText(), /600 kcal/);
  assert.deepEqual(page.errors, []);
});

test('grams instead of portions, then the recipe shows under "recently eaten"', async (t) => {
  const page = await recipeSearch(t);
  await page.locator('#rl', { hasText: 'Wpisz nazwę' }).waitFor();
  await page.fill('#rq', 'kurczak');
  await page.locator('.recipe', { hasText: 'Kurczak z ryżem' }).click();
  assert.equal(await page.locator('#rgf').isVisible(), false);
  await page.locator('#raf .seg label', { hasText: 'gramy' }).click();
  await page.fill('#raf [name=grams]', '200');
  assert.match(await page.locator('#rsum').innerText(), /300 kcal/);
  await page.click('#raf button[type=submit]');
  await page.locator('.card.meal', { hasText: 'Kurczak z ryżem i brokułem' }).waitFor();

  await page.click('#fab');
  await page.click('#recipe');
  await page.locator('#rl', { hasText: 'Ostatnio jedzone' }).waitFor();
  assert.match(await page.locator('#rl .recipe').first().innerText(), /Kurczak z ryżem i brokułem/);
  assert.deepEqual(page.errors, []);
});

test('nothing found, and back from a recipe to the list keeps the search', async (t) => {
  const page = await recipeSearch(t);
  await page.fill('#rq', 'pizza');
  await page.locator('#rl', { hasText: 'Nie ma takiego przepisu' }).waitFor();
  await page.fill('#rq', 'grecka');
  await page.locator('.recipe', { hasText: 'Sałatka grecka' }).first().click();
  await page.click('#rback');
  assert.equal(await page.inputValue('#rq'), 'grecka');
  await page.locator('.recipe', { hasText: 'Sałatka grecka' }).first().waitFor();
  assert.deepEqual(page.errors, []);
});

// The backend runs in the test env, so Cookidoo pages come from tests/fixtures/cookidoo.
test('a pasted Cookidoo share text imports the recipe and logs it', async (t) => {
  const page = await recipeSearch(t);
  await page.fill('#rq', 'Sprawdź ten pyszny przepis znaleziony na Cookidoo®: Zupa https://cookidoo.pl/recipes/recipe/pl-PL/r100001');
  await page.locator('#raf').waitFor();
  assert.match(await page.locator('.sheet h3').innerText(), /Zupa testowa z dyni & imbirem/);
  assert.match(await page.locator('.sheet .hint').first().innerText(), /~283 g · 200 kcal · z Cookidoo/);
  await page.click('#raf button[type=submit]');
  await page.locator('.card.meal', { hasText: 'Zupa testowa z dyni' }).waitFor();

  await page.click('#fab');
  await page.click('#recipe');
  await page.fill('#rq', 'zupa testowa');
  assert.match(await page.locator('.recipe', { hasText: 'Zupa testowa' }).innerText(), /Cookidoo/);
  assert.deepEqual(page.errors, []);
});

test('a Cookidoo link without values shows the error in the sheet', async (t) => {
  const page = await recipeSearch(t);
  await page.fill('#rq', 'https://cookidoo.pl/recipes/recipe/pl-PL/r100002');
  await page.locator('#rl .error-box', { hasText: 'nie ma wartości odżywczych' }).waitFor();
  assert.deepEqual(page.errors, []);
});
