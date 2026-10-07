import { randomBytes, randomUUID } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { describe, matches, promptKey } from './prompts.js';
import { promptBonus, scoreWord, worldMultiplier } from './scoring.js';

export const DEFAULT_PROMPT_DURATION_MS = 24 * 60 * 60 * 1000;
export const PUBLIC_WORLD_ID = 'public';
const NICKNAME_PATTERN = /^[\p{L}\p{N}_ -]{1,20}$/u;

const SCHEMA = `
  CREATE TABLE IF NOT EXISTS worlds (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    prompt_duration_ms INTEGER NOT NULL,
    created_at INTEGER NOT NULL
  );
  CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY,
    world_id TEXT NOT NULL REFERENCES worlds(id),
    nickname TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    created_at INTEGER NOT NULL,
    UNIQUE (world_id, nickname)
  );
  CREATE TABLE IF NOT EXISTS prompts (
    id INTEGER PRIMARY KEY,
    world_id TEXT NOT NULL REFERENCES worlds(id),
    type TEXT NOT NULL,
    letters TEXT NOT NULL,
    available_at_start INTEGER NOT NULL,
    started_at INTEGER NOT NULL,
    ends_at INTEGER NOT NULL,
    ended_at INTEGER
  );
  CREATE TABLE IF NOT EXISTS burns (
    world_id TEXT NOT NULL REFERENCES worlds(id),
    word TEXT NOT NULL,
    played_word TEXT NOT NULL,
    player_id INTEGER NOT NULL REFERENCES players(id),
    prompt_id INTEGER NOT NULL REFERENCES prompts(id),
    points INTEGER NOT NULL,
    burned_at INTEGER NOT NULL,
    PRIMARY KEY (world_id, word)
  );
`;

export class GameError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

export class Game {
  constructor({ dictionary, promptIndex, dbPath = ':memory:', now = Date.now, rng = Math.random, promptRange }) {
    this.dictionary = dictionary;
    this.promptIndex = promptIndex;
    this.now = now;
    this.rng = rng;
    this.promptRange = promptRange ?? { min: 15, max: 200 };
    this.db = new DatabaseSync(dbPath);
    this.db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
    this.db.exec(SCHEMA);
    this.burnedCache = new Map(); // worldId -> Set of burned words
  }

  // --- Worlds and players ---

  createWorld({ name, id = randomUUID().slice(0, 8), promptDurationMs = DEFAULT_PROMPT_DURATION_MS }) {
    const cleanName = String(name ?? '').trim().slice(0, 40) || 'Unnamed world';
    this.db.prepare('INSERT INTO worlds (id, name, prompt_duration_ms, created_at) VALUES (?, ?, ?, ?)')
      .run(id, cleanName, promptDurationMs, this.now());
    this.currentPrompt(id);
    return this.world(id);
  }

  ensureWorld(id, name) {
    return this.db.prepare('SELECT * FROM worlds WHERE id = ?').get(id) ? this.world(id) : this.createWorld({ id, name });
  }

  world(id) {
    const world = this.db.prepare('SELECT * FROM worlds WHERE id = ?').get(id);
    if (!world) throw new GameError(404, 'World not found');
    return world;
  }

  join(worldId, nickname) {
    this.world(worldId);
    const name = String(nickname ?? '').trim();
    if (!NICKNAME_PATTERN.test(name)) {
      throw new GameError(400, 'Nicknames are 1–20 letters, numbers, spaces, - or _');
    }
    const taken = this.db.prepare('SELECT 1 FROM players WHERE world_id = ? AND nickname = ? COLLATE NOCASE').get(worldId, name);
    if (taken) throw new GameError(409, 'That nickname is taken in this world');
    const token = randomBytes(24).toString('hex');
    const { lastInsertRowid } = this.db.prepare('INSERT INTO players (world_id, nickname, token, created_at) VALUES (?, ?, ?, ?)')
      .run(worldId, name, token, this.now());
    return { playerId: Number(lastInsertRowid), nickname: name, token };
  }

  playerByToken(worldId, token) {
    const player = this.db.prepare('SELECT * FROM players WHERE world_id = ? AND token = ?').get(worldId, String(token ?? ''));
    if (!player) throw new GameError(401, 'Join this world first');
    return player;
  }

  // --- Burned words ---

  burned(worldId) {
    if (!this.burnedCache.has(worldId)) {
      const rows = this.db.prepare('SELECT word FROM burns WHERE world_id = ?').all(worldId);
      this.burnedCache.set(worldId, new Set(rows.map((r) => r.word)));
    }
    return this.burnedCache.get(worldId);
  }

  lookup(worldId, rawWord) {
    this.world(worldId);
    const word = String(rawWord ?? '').trim().toLowerCase();
    const row = this.db.prepare(`
      SELECT b.word, b.played_word, b.points, b.burned_at, p.nickname
      FROM burns b JOIN players p ON p.id = b.player_id
      WHERE b.world_id = ? AND b.word = ?`).get(worldId, word);
    return {
      word,
      inDictionary: this.dictionary.has(word),
      burned: row ? { by: row.nickname, at: row.burned_at, playedWord: row.played_word } : null,
    };
  }

  // --- Prompts ---

  // Returns the active prompt, rotating it first if it has expired or run dry.
  currentPrompt(worldId) {
    const world = this.world(worldId);
    const burned = this.burned(worldId);
    let prompt = this.db.prepare('SELECT * FROM prompts WHERE world_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1').get(worldId);
    if (prompt) {
      const remaining = this.promptIndex.countUnburned(prompt, (w) => burned.has(w));
      if (remaining > 0 && this.now() < prompt.ends_at) return { ...prompt, remaining };
      this.db.prepare('UPDATE prompts SET ended_at = ? WHERE id = ?').run(this.now(), prompt.id);
    }
    const used = new Set(this.db.prepare('SELECT type, letters FROM prompts WHERE world_id = ?').all(worldId).map(promptKey));
    const next = this.promptIndex.pick({ isBurned: (w) => burned.has(w), used, rng: this.rng, ...this.promptRange });
    if (!next) return null; // The dictionary is exhausted.
    const startedAt = this.now();
    const { lastInsertRowid } = this.db.prepare(`
      INSERT INTO prompts (world_id, type, letters, available_at_start, started_at, ends_at)
      VALUES (?, ?, ?, ?, ?, ?)`).run(worldId, next.type, next.letters, next.available, startedAt, startedAt + world.prompt_duration_ms);
    prompt = this.db.prepare('SELECT * FROM prompts WHERE id = ?').get(lastInsertRowid);
    return { ...prompt, remaining: next.available };
  }

  multipliers(worldId, prompt) {
    return {
      world: worldMultiplier(this.burned(worldId).size, this.dictionary.size),
      prompt: prompt ? promptBonus(prompt.remaining, prompt.available_at_start) : 1,
    };
  }

  // --- Playing ---

  play(worldId, token, rawWord) {
    const player = this.playerByToken(worldId, token);
    const word = String(rawWord ?? '').trim().toLowerCase();
    const prompt = this.currentPrompt(worldId);
    if (!prompt) throw new GameError(410, 'This world has run out of words');

    if (!this.dictionary.has(word)) return { ok: false, word, reason: 'not-a-word' };
    if (!matches(prompt, word)) return { ok: false, word, reason: 'doesnt-fit' };
    const burned = this.burned(worldId);
    if (burned.has(word)) return { ok: false, word, reason: 'already-burned', burned: this.lookup(worldId, word).burned };

    const mult = this.multipliers(worldId, prompt);
    const points = scoreWord({ word, rarity: this.dictionary.rarity(word), worldMult: mult.world, promptMult: mult.prompt });
    const family = this.dictionary.family(word).filter((w) => !burned.has(w));
    const at = this.now();

    const insert = this.db.prepare(`
      INSERT INTO burns (world_id, word, played_word, player_id, prompt_id, points, burned_at)
      VALUES (?, ?, ?, ?, ?, ?, ?)`);
    this.db.exec('BEGIN');
    try {
      for (const w of family) insert.run(worldId, w, word, player.id, prompt.id, w === word ? points : 0, at);
      this.db.exec('COMMIT');
    } catch (err) {
      this.db.exec('ROLLBACK');
      throw err;
    }
    for (const w of family) burned.add(w);

    return {
      ok: true,
      word,
      points,
      alsoBurned: family.filter((w) => w !== word),
      multipliers: mult,
    };
  }

  // --- State for the client ---

  state(worldId, token) {
    const world = this.world(worldId);
    const prompt = this.currentPrompt(worldId);
    const mult = this.multipliers(worldId, prompt);
    const recent = this.db.prepare(`
      SELECT b.word, b.points, b.burned_at, p.nickname,
        (SELECT COUNT(*) - 1 FROM burns f WHERE f.world_id = b.world_id AND f.played_word = b.word AND f.burned_at = b.burned_at) AS family_count
      FROM burns b JOIN players p ON p.id = b.player_id
      WHERE b.world_id = ? AND b.word = b.played_word
      ORDER BY b.burned_at DESC, b.rowid DESC LIMIT 25`).all(worldId);
    const leaderboard = this.db.prepare(`
      SELECT p.nickname,
        COALESCE(SUM(b.points), 0) AS total,
        COALESCE(SUM(CASE WHEN b.prompt_id = ? THEN b.points END), 0) AS this_prompt,
        COUNT(CASE WHEN b.word = b.played_word THEN 1 END) AS words
      FROM players p LEFT JOIN burns b ON b.player_id = p.id
      WHERE p.world_id = ?
      GROUP BY p.id ORDER BY total DESC, words DESC, p.nickname LIMIT 50`).all(prompt?.id ?? -1, worldId);
    let me = null;
    if (token) {
      const player = this.db.prepare('SELECT nickname FROM players WHERE world_id = ? AND token = ?').get(worldId, token);
      if (player) me = player.nickname;
    }
    return {
      world: { id: world.id, name: world.name },
      me,
      prompt: prompt && {
        type: prompt.type,
        letters: prompt.letters,
        label: describe(prompt),
        remaining: prompt.remaining,
        availableAtStart: prompt.available_at_start,
        endsAt: prompt.ends_at,
      },
      multipliers: mult,
      dictionary: { size: this.dictionary.size, burned: this.burned(worldId).size },
      recent: recent.map((r) => ({ word: r.word, points: r.points, by: r.nickname, at: r.burned_at, familyCount: r.family_count })),
      leaderboard: leaderboard.map((r) => ({ nickname: r.nickname, total: r.total, thisPrompt: r.this_prompt, words: r.words })),
      now: this.now(),
    };
  }
}
