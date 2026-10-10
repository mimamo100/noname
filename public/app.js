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
  let data;
  try {
    data = await res.json();
  } catch {
    // Not JSON: the request never reached the game's PHP code (missing .htaccess, old PHP, wrong document root).
    throw Object.assign(new Error(`The game's server isn't responding properly (HTTP ${res.status}). Visit /check.php to find out why.`), { status: res.status });
  }
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

  $('tab-misses').hidden = !joined;
  renderMisses(state.myMisses ?? []);

  $('leaderboard').replaceChildren(...state.leaderboard.map((p) => el('tr', { className: p.nickname === state.me ? 'me' : '' },
    el('td', { textContent: p.nickname }),
    el('td', { textContent: p.words }),
    el('td', { textContent: p.thisPrompt }),
    el('td', { textContent: p.total }),
  )));
}

let lastStateLoaded = false;

const MISS_REASONS = {
  'not-a-word': 'Not in the dictionary',
  'doesnt-fit': "Didn't fit",
  'already-burned': 'Already said',
  'not-in-category': 'Not on our list',
};

function renderMisses(misses) {
  if (!misses.length) {
    $('misses-list').replaceChildren(el('li', { className: 'muted', textContent: 'No misses yet. Nice.' }));
    return;
  }
  const rows = [];
  let lastPrompt = null;
  for (const m of misses) {
    if (m.promptId !== lastPrompt) {
      rows.push(el('li', { className: 'miss-prompt', textContent: m.prompt }));
      lastPrompt = m.promptId;
    }
    rows.push(el('li', {},
      el('span', { className: 'miss-word', textContent: m.word }),
      el('span', { className: 'muted', textContent: ` ${MISS_REASONS[m.reason] ?? m.reason}` }),
      el('span', { className: m.penalty ? 'points penalty' : 'points free', textContent: m.penalty ? `−${m.penalty}` : 'free' }),
    ));
  }
  $('misses-list').replaceChildren(...rows);
}

function showTab(name) {
  const misses = name === 'misses' && !$('tab-misses').hidden;
  $('tab-recent').setAttribute('aria-selected', String(!misses));
  $('tab-misses').setAttribute('aria-selected', String(misses));
  $('recent').hidden = misses;
  $('misses').hidden = !misses;
  try { localStorage.setItem('unsaid:tab', misses ? 'misses' : 'recent'); } catch { /* not saved: fine */ }
}
$('tab-recent').addEventListener('click', () => showTab('recent'));
$('tab-misses').addEventListener('click', () => showTab('misses'));

async function refresh() {
  try {
    render(await api(`/${encodeURIComponent(worldId)}`));
    if (!lastStateLoaded) {
      let saved = 'recent';
      try { saved = localStorage.getItem('unsaid:tab') ?? 'recent'; } catch { /* default tab */ }
      showTab(saved);
    }
    lastStateLoaded = true;
  } catch (err) {
    if (lastStateLoaded) return; // A brief hiccup while playing: keep showing the game and retry on the next poll.
    $('prompt-label').textContent = err.status === 404 && err.message === 'World not found' ? 'World not found' : 'Something went wrong';
    feedback(err.message, 'bad');
  }
}

function feedback(text, kind) {
  $('feedback').textContent = text;
  $('feedback').className = `feedback ${kind}`;
}

const penaltyText = (r) => (r.penalty ? ` −${r.penalty} ${r.penalty === 1 ? 'point' : 'points'}.` : '');

const REASONS = {
  'not-a-word': (w) => `“${w}” isn't in the dictionary.`,
  'doesnt-fit': (w, r) => `“${w}” doesn't fit the prompt.${penaltyText(r)}`,
  'not-in-category': (w, r) => `“${w}” isn't on our list for “${r.category}”. No penalty.`,
  'already-burned': (w, r) => r.burned
    ? `“${w}” has already been said — by ${r.burned.by}${r.burned.playedWord !== w ? ` (with “${r.burned.playedWord}”)` : ''}.${penaltyText(r)}`
    : `“${w}” has already been said.${penaltyText(r)}`,
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
      if (result.reason === 'not-in-category') $('feedback').append(' ', reportButton(result.word));
      input.select();
    }
    refresh();
  } catch (err) {
    feedback(err.message, 'bad');
  }
});

// Lets players tell us when a real answer is missing from a meaning list.
function reportButton(word) {
  const button = el('button', { type: 'button', className: 'link', textContent: 'Report it — it should count' });
  button.addEventListener('click', async () => {
    try {
      await api(`/${encodeURIComponent(worldId)}/reports`, { method: 'POST', body: { word } });
      button.replaceWith('Thanks, reported.');
    } catch (err) {
      button.replaceWith(err.message);
    }
  });
  return button;
}

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
