const POLL_MS = 3000;
const worldId = location.pathname.startsWith('/w/') ? decodeURIComponent(location.pathname.slice(3)) : 'public';
const tokenKey = `unsaid:token:${worldId}`;
const $ = (id) => document.getElementById(id);

function getToken() {
  try { return localStorage.getItem(tokenKey); } catch { return null; }
}
function setToken(token) {
  try { localStorage.setItem(tokenKey, token); } catch { /* private mode: token lasts for this page only */ }
  memoryToken = token;
}
let memoryToken = getToken();

async function api(path, { method = 'GET', body } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (memoryToken) headers['X-Player-Token'] = memoryToken;
  const res = await fetch(`/api/worlds${path}`, { method, headers, body: body && JSON.stringify(body) });
  const data = await res.json();
  if (!res.ok) throw Object.assign(new Error(data.error ?? 'Request failed'), { status: res.status });
  return data;
}

function timeLeft(ms) {
  if (ms <= 0) return 'now';
  const mins = Math.ceil(ms / 60000);
  if (mins < 60) return `${mins}m`;
  const hours = Math.floor(mins / 60);
  return `${hours}h ${mins % 60}m`;
}

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  Object.assign(node, attrs);
  node.append(...children);
  return node;
}

function render(state) {
  document.title = `${state.world.name} · Unsaid`;
  $('world-name').textContent = state.world.name;
  $('dict-size').textContent = state.dictionary.size.toLocaleString();
  $('dict-burned').textContent = state.dictionary.burned.toLocaleString();
  $('world-mult').textContent = state.multipliers.world.toFixed(1);
  $('prompt-mult').textContent = state.multipliers.prompt.toFixed(1);

  if (state.prompt) {
    $('prompt-label').textContent = state.prompt.label;
    $('remaining').textContent = state.prompt.remaining;
    const used = 1 - state.prompt.remaining / state.prompt.availableAtStart;
    $('meter-fill').style.width = `${Math.round(used * 100)}%`;
    $('ends-in').textContent = timeLeft(state.prompt.endsAt - state.now);
  } else {
    $('prompt-label').textContent = 'This world has run out of words';
    $('remaining').textContent = '0';
  }

  const joined = Boolean(state.me);
  $('play-form').hidden = !joined || !state.prompt;
  $('join-form').hidden = joined;

  $('recent').replaceChildren(...(state.recent.length ? state.recent.map((r) => el('li', {},
    el('span', { className: 'said-word', textContent: r.word }),
    el('span', { className: 'muted', textContent: ` by ${r.by}${r.familyCount ? ` (+${r.familyCount} family)` : ''}` }),
    el('span', { className: 'points', textContent: `+${r.points}` }),
  )) : [el('li', { className: 'muted', textContent: 'Nothing said yet. Be the first.' })]));

  $('leaderboard').replaceChildren(...state.leaderboard.map((p) => el('tr', { className: p.nickname === state.me ? 'me' : '' },
    el('td', { textContent: p.nickname }),
    el('td', { textContent: p.words }),
    el('td', { textContent: p.thisPrompt }),
    el('td', { textContent: p.total }),
  )));
}

async function refresh() {
  try {
    render(await api(`/${encodeURIComponent(worldId)}`));
  } catch (err) {
    if (err.status === 404) $('prompt-label').textContent = 'World not found';
  }
}

function feedback(text, kind) {
  $('feedback').textContent = text;
  $('feedback').className = `feedback ${kind}`;
}

const REASONS = {
  'not-a-word': (w) => `“${w}” isn't in the dictionary.`,
  'doesnt-fit': (w) => `“${w}” doesn't fit the prompt.`,
  'already-burned': (w, r) => r.burned
    ? `“${w}” has already been said — by ${r.burned.by}${r.burned.playedWord !== w ? ` (with “${r.burned.playedWord}”)` : ''}.`
    : `“${w}” has already been said.`,
};

$('play-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const input = $('word');
  const word = input.value.trim();
  if (!word) return;
  try {
    const result = await api(`/${encodeURIComponent(worldId)}/words`, { method: 'POST', body: { word } });
    if (result.ok) {
      const family = result.alsoBurned.length ? ` Gone with it: ${result.alsoBurned.join(', ')}.` : '';
      feedback(`You said “${result.word}” for ${result.points} ${result.points === 1 ? 'point' : 'points'}. Nobody can say it again.${family}`, 'good');
      input.value = '';
    } else {
      feedback(REASONS[result.reason](result.word, result), 'bad');
      input.select();
    }
    refresh();
  } catch (err) {
    feedback(err.message, 'bad');
  }
});

$('join-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const { token } = await api(`/${encodeURIComponent(worldId)}/join`, { method: 'POST', body: { nickname: $('nickname').value } });
    setToken(token);
    feedback('Welcome! Start saying words.', 'good');
    await refresh();
    $('word').focus();
  } catch (err) {
    feedback(err.message, 'bad');
  }
});

$('lookup-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const word = $('lookup').value.trim().toLowerCase();
  if (!word) return;
  try {
    const r = await api(`/${encodeURIComponent(worldId)}/words/${encodeURIComponent(word)}`);
    $('lookup-result').textContent = !r.inDictionary ? `“${r.word}” isn't in the dictionary.`
      : r.burned ? `“${r.word}” was said by ${r.burned.by} on ${new Date(r.burned.at).toLocaleString()}${r.burned.playedWord !== r.word ? ` (with “${r.burned.playedWord}”)` : ''}.`
      : `“${r.word}” is still unsaid.`;
  } catch (err) {
    $('lookup-result').textContent = err.message;
  }
});

$('copy-link').hidden = worldId === 'public';
$('copy-link').addEventListener('click', async () => {
  try {
    await navigator.clipboard.writeText(location.href);
    feedback('Invite link copied. Send it to your friends!', 'good');
  } catch {
    feedback(`Share this link: ${location.href}`, 'good');
  }
});

$('new-world').addEventListener('click', async () => {
  const name = prompt('Name your world (you can share the link with friends):');
  if (name === null) return;
  const world = await api('', { method: 'POST', body: { name } });
  location.href = `/w/${encodeURIComponent(world.id)}`;
});

setInterval(refresh, POLL_MS);
refresh();
