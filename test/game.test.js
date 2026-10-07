import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Dictionary } from '../src/dictionary.js';
import { PromptIndex, matches } from '../src/prompts.js';
import { promptBonus, scoreWord, worldMultiplier } from '../src/scoring.js';
import { Game, GameError } from '../src/game.js';

const WORDS = [
  'cat', 'cats', 'run', 'running', 'runs', 'moon', 'moons', 'book', 'books', 'cool',
  'stop', 'stopped', 'agree', 'agreed', 'see', 'seed', 'hop', 'hope', 'hoped',
  'rat', 'rate', 'rates', 'sing', 'singe', 'singing', 'she', 'shed', 'city', 'cities', 'boot', 'food',
  'catch', 'start', 'stare', 'stone', 'stoop', 'story', 'mood', 'noon', 'zoo', 'zoom',
];

function makeGame({ now = () => 1_000, words = WORDS, promptRange = { min: 1, max: 1000 } } = {}) {
  const dictionary = new Dictionary(words);
  return new Game({ dictionary, promptIndex: new PromptIndex(dictionary), now, rng: () => 0.5, promptRange });
}

// Forces a known prompt so tests don't depend on random picks.
function setPrompt(game, worldId, type, letters) {
  game.db.prepare('UPDATE prompts SET type = ?, letters = ? WHERE world_id = ? AND ended_at IS NULL').run(type, letters, worldId);
}

test('word families group plurals and inflections', () => {
  const d = new Dictionary(WORDS);
  assert.deepEqual(d.family('cats').sort(), ['cat', 'cats']);
  assert.deepEqual(d.family('running').sort(), ['run', 'running', 'runs']);
  assert.deepEqual(d.family('cities').sort(), ['cities', 'city']);
  assert.deepEqual(d.family('stopped').sort(), ['stop', 'stopped']);
  assert.deepEqual(d.family('agreed').sort(), ['agree', 'agreed']);
  assert.deepEqual(d.family('hoped').sort(), ['hope', 'hoped'], 'hoped is not a form of hop');
  assert.deepEqual(d.family('rates').sort(), ['rate', 'rates'], 'rates is not a form of rat');
  assert.deepEqual(d.family('singing').sort(), ['sing', 'singing'], 'singing is not a form of singe');
  assert.deepEqual(d.family('seed'), ['seed'], 'seed is not a form of see');
  assert.deepEqual(d.family('shed'), ['shed'], 'shed is not a form of she');
  assert.deepEqual(d.family('catch'), ['catch']);
});

test('the real dictionary loads and builds families', () => {
  const d = Dictionary.load();
  assert.ok(d.size > 30000);
  assert.ok(d.family('dogs').includes('dog'));
  assert.ok(d.has('moon'));
});

test('prompts match words', () => {
  assert.ok(matches({ type: 'starts', letters: 'st' }, 'stone'));
  assert.ok(matches({ type: 'ends', letters: 'on' }, 'moon'));
  assert.ok(matches({ type: 'contains', letters: 'oo' }, 'book'));
  assert.ok(!matches({ type: 'contains', letters: 'oo' }, 'cat'));
});

test('prompt picker respects the range and skips used prompts', () => {
  const d = new Dictionary(WORDS);
  const index = new PromptIndex(d);
  const picked = index.pick({ isBurned: () => false, min: 5, max: 20, rng: () => 0.3 });
  assert.ok(picked.available >= 5 && picked.available <= 20);
  const again = index.pick({ isBurned: () => false, min: 5, max: 20, rng: () => 0.3, used: new Set([`${picked.type}:${picked.letters}`]) });
  assert.notDeepEqual([again.type, again.letters], [picked.type, picked.letters]);
});

test('scoring rises with length, rarity and scarcity', () => {
  assert.equal(worldMultiplier(0, 100), 1);
  assert.equal(worldMultiplier(50, 100), 2);
  assert.equal(Math.round(worldMultiplier(80, 100)), 5);
  assert.equal(worldMultiplier(100, 100), 10);
  assert.equal(promptBonus(40, 40), 1);
  assert.equal(promptBonus(10, 40), 4);
  assert.equal(promptBonus(1, 40), 5);
  const base = { worldMult: 1, promptMult: 1 };
  assert.equal(scoreWord({ word: 'cat', rarity: 0, ...base }), 1);
  assert.ok(scoreWord({ word: 'cataract', rarity: 0, ...base }) > scoreWord({ word: 'cat', rarity: 0, ...base }));
  assert.ok(scoreWord({ word: 'moon', rarity: 0.9, ...base }) > scoreWord({ word: 'moon', rarity: 0, ...base }));
});

test('playing a word burns it and its family for everyone', () => {
  const game = makeGame();
  const world = game.createWorld({ name: 'Test' });
  setPrompt(game, world.id, 'contains', 'oo');
  const alice = game.join(world.id, 'Alice');
  const bob = game.join(world.id, 'Bob');

  const played = game.play(world.id, alice.token, 'Moon');
  assert.equal(played.ok, true);
  assert.deepEqual(played.alsoBurned, ['moons']);
  assert.ok(played.points >= 1);

  const again = game.play(world.id, bob.token, 'moons');
  assert.equal(again.reason, 'already-burned');
  assert.equal(again.burned.by, 'Alice');
  assert.equal(again.burned.playedWord, 'moon');

  assert.equal(game.play(world.id, bob.token, 'cat').reason, 'doesnt-fit');
  assert.equal(game.play(world.id, bob.token, 'xyzzy').reason, 'not-a-word');

  const state = game.state(world.id, bob.token);
  assert.equal(state.me, 'Bob');
  assert.equal(state.dictionary.burned, 2);
  assert.equal(state.recent[0].word, 'moon');
  assert.equal(state.recent[0].familyCount, 1);
  assert.equal(state.leaderboard[0].nickname, 'Alice');
});

test('burns are separate per world', () => {
  const game = makeGame();
  const a = game.createWorld({ name: 'A' });
  const b = game.createWorld({ name: 'B' });
  setPrompt(game, a.id, 'contains', 'oo');
  setPrompt(game, b.id, 'contains', 'oo');
  assert.ok(game.play(a.id, game.join(a.id, 'Sam').token, 'book').ok);
  assert.ok(game.play(b.id, game.join(b.id, 'Sam').token, 'book').ok);
});

test('the prompt rotates when it runs dry or times out', () => {
  let clock = 1_000;
  const game = makeGame({ now: () => clock });
  const world = game.createWorld({ name: 'Rotate', promptDurationMs: 60_000 });
  setPrompt(game, world.id, 'starts', 'zo');
  const p = game.join(world.id, 'Pat');
  assert.ok(game.play(world.id, p.token, 'zoo').ok);
  assert.ok(game.play(world.id, p.token, 'zoom').ok);
  const afterDry = game.state(world.id).prompt;
  assert.notEqual(`${afterDry.type}:${afterDry.letters}`, 'starts:zo');

  clock += 61_000;
  const afterTimeout = game.state(world.id).prompt;
  assert.notEqual(`${afterTimeout.type}:${afterTimeout.letters}`, `${afterDry.type}:${afterDry.letters}`);
});

test('nicknames are validated and unique per world', () => {
  const game = makeGame();
  const world = game.createWorld({ name: 'Names' });
  game.join(world.id, 'Robin');
  assert.throws(() => game.join(world.id, 'robin'), (e) => e instanceof GameError && e.status === 409);
  assert.throws(() => game.join(world.id, ''), (e) => e.status === 400);
  assert.throws(() => game.join(world.id, '<script>'), (e) => e.status === 400);
  assert.throws(() => game.play(world.id, 'bad-token', 'cat'), (e) => e.status === 401);
});
