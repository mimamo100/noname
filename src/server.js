import { createServer } from 'node:http';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Dictionary } from './dictionary.js';
import { PromptIndex } from './prompts.js';
import { Game, GameError, PUBLIC_WORLD_ID } from './game.js';

const PUBLIC_DIR = fileURLToPath(new URL('../public/', import.meta.url));
const CONTENT_TYPES = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml' };
const MAX_BODY_BYTES = 10_000;

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(body));
}

async function readJson(req) {
  let size = 0;
  const chunks = [];
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new GameError(413, 'Request too large');
    chunks.push(chunk);
  }
  try {
    return chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {};
  } catch {
    throw new GameError(400, 'Invalid JSON');
  }
}

function serveStatic(res, pathname) {
  const file = pathname === '/' || pathname.startsWith('/w/') ? 'index.html' : pathname.slice(1);
  const path = normalize(join(PUBLIC_DIR, file));
  if (!path.startsWith(PUBLIC_DIR)) return send(res, 404, { error: 'Not found' });
  try {
    const body = readFileSync(path);
    res.writeHead(200, { 'Content-Type': CONTENT_TYPES[extname(path)] ?? 'application/octet-stream' });
    res.end(body);
  } catch {
    send(res, 404, { error: 'Not found' });
  }
}

export function createApp(game) {
  return createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const parts = url.pathname.split('/').filter(Boolean).map(decodeURIComponent);
    const token = req.headers['x-player-token'];
    try {
      if (parts[0] !== 'api') return serveStatic(res, url.pathname);
      const [, resource, worldId, action, arg] = parts;
      if (resource !== 'worlds') throw new GameError(404, 'Not found');

      if (req.method === 'POST' && !worldId) {
        const { name } = await readJson(req);
        return send(res, 201, game.createWorld({ name }));
      }
      if (req.method === 'GET' && !action) return send(res, 200, game.state(worldId, token));
      if (req.method === 'POST' && action === 'join') {
        const { nickname } = await readJson(req);
        return send(res, 201, game.join(worldId, nickname));
      }
      if (req.method === 'POST' && action === 'words') {
        const { word } = await readJson(req);
        return send(res, 200, game.play(worldId, token, word));
      }
      if (req.method === 'GET' && action === 'words' && arg) return send(res, 200, game.lookup(worldId, arg));
      throw new GameError(404, 'Not found');
    } catch (err) {
      if (err instanceof GameError) return send(res, err.status, { error: err.message });
      console.error(err);
      send(res, 500, { error: 'Something went wrong' });
    }
  });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const dataDir = process.env.DATA_DIR ?? 'var';
  mkdirSync(dataDir, { recursive: true });
  const dictionary = Dictionary.load();
  const game = new Game({ dictionary, promptIndex: new PromptIndex(dictionary), dbPath: join(dataDir, 'burned-words.db') });
  game.ensureWorld(PUBLIC_WORLD_ID, 'The Public World');
  const port = Number(process.env.PORT ?? 3000);
  createApp(game).listen(port, () => console.log(`Burned Words running at http://localhost:${port}`));
}
