import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Dictionary } from '../src/dictionary.js';
import { PromptIndex } from '../src/prompts.js';
import { Game } from '../src/game.js';
import { createApp } from '../src/server.js';

test('HTTP API: create, join, play, look up', async (t) => {
  const dictionary = Dictionary.load();
  const game = new Game({ dictionary, promptIndex: new PromptIndex(dictionary) });
  const server = createApp(game).listen(0);
  t.after(() => server.close());
  await new Promise((resolve) => server.once('listening', resolve));
  const base = `http://localhost:${server.address().port}`;
  const call = async (path, { method = 'GET', body, token } = {}) => {
    const res = await fetch(base + path, {
      method,
      headers: { 'Content-Type': 'application/json', ...(token && { 'X-Player-Token': token }) },
      body: body && JSON.stringify(body),
    });
    return { status: res.status, body: await res.json() };
  };

  const created = await call('/api/worlds', { method: 'POST', body: { name: 'Friends' } });
  assert.equal(created.status, 201);
  const id = created.body.id;

  const joined = await call(`/api/worlds/${id}/join`, { method: 'POST', body: { nickname: 'Kim' } });
  assert.equal(joined.status, 201);

  const state = await call(`/api/worlds/${id}`, { token: joined.body.token });
  assert.equal(state.body.me, 'Kim');
  const { type, letters } = state.body.prompt;

  // Find a word that fits the current prompt and play it.
  const word = [...game.promptIndex.wordsFor({ type, letters })][0];
  const played = await call(`/api/worlds/${id}/words`, { method: 'POST', body: { word }, token: joined.body.token });
  assert.equal(played.body.ok, true);

  const lookup = await call(`/api/worlds/${id}/words/${word}`);
  assert.equal(lookup.body.burned.by, 'Kim');

  assert.equal((await call(`/api/worlds/${id}/words`, { method: 'POST', body: { word } })).status, 401);
  assert.equal((await call('/api/worlds/nope')).status, 404);

  const page = await fetch(`${base}/w/${id}`);
  assert.equal(page.status, 200);
  assert.match(await page.text(), /Burned Words/);
  assert.equal((await fetch(`${base}/../package.json`)).status, 404);
});
