import { test } from 'node:test';
import assert from 'node:assert/strict';
import { phone, loggedIn, open, today } from './lib.mjs';

async function newIngredient(t) {
  const page = await phone(t);
  await loggedIn(page);
  await open(page, `/meal/new/${await today(page)}`);
  await page.click('#add-item');
  const form = page.locator('#itf');
  const field = (name) => form.locator(`[name=${name}]`);
  return { page, form, field };
}

// 2026-10-22: per-gram values came from the original item, so a new ingredient
// (100 g, 0 kcal) lost every typed value on the first grams change.
test('a grams change scales hand-typed values instead of wiping them', async (t) => {
  const { page, form, field } = await newIngredient(t);
  await field('name').fill('Ser');
  await field('kcal').fill('250');
  await field('protein_g').fill('10');
  await form.locator('[data-step="10"]').click();
  assert.equal(await field('grams').inputValue(), '110');
  assert.equal(await field('kcal').inputValue(), '275');
  assert.equal(await field('protein_g').inputValue(), '11');

  await field('grams').fill('220');
  assert.equal(await field('kcal').inputValue(), '550');
  assert.deepEqual(page.errors, []);
});

test('a value corrected by hand is what later grams changes scale', async (t) => {
  const { page, form, field } = await newIngredient(t);
  await field('name').fill('Ser');
  await field('grams').fill('220');
  await field('kcal').fill('300');
  await form.locator('[data-step="-10"]').click();
  assert.equal(await field('kcal').inputValue(), '286.4');
  assert.deepEqual(page.errors, []);
});

test('clearing grams keeps the values, new grams scale them', async (t) => {
  const { page, form, field } = await newIngredient(t);
  await field('name').fill('Ser');
  await field('kcal').fill('200');
  await field('grams').fill('');
  assert.equal(await field('kcal').inputValue(), '200');
  await field('grams').fill('50');
  assert.equal(await field('kcal').inputValue(), '100');

  await form.locator('button[type=submit]').click();
  assert.match(await page.locator('.item').first().innerText(), /50 g\s+100 kcal/);
  assert.deepEqual(page.errors, []);
});
