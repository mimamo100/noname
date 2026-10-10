const POLL_MS = 3000;
const worldId = location.pathname.startsWith('/w/') ? decodeURIComponent(location.pathname.slice(3)) : 'public';
const SESSION_KEY = 'unsaid:session';
const $ = (id) => document.getElementById(id);

// The signed-in session lives in localStorage, so the device stays signed in.
let session = null;
try { session = localStorage.getItem(SESSION_KEY); } catch { /* private mode: signed in for this page only */ }
function setSession(token) {
  session = token;
  try {
    if (token) localStorage.setItem(SESSION_KEY, token);
    else localStorage.removeItem(SESSION_KEY);
  } catch { /* not saved: fine */ }
}

// Player tokens from before accounts, so signing in can claim those players and their scores.
function oldPlayerTokens() {
  const tokens = [];
  try {
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key.startsWith('unsaid:token:') || key.startsWith('burned-words:token:')) tokens.push(localStorage.getItem(key));
    }
  } catch { /* nothing to claim */ }
  return tokens;
}
function forgetOldPlayerTokens() {
  try {
    for (const key of Object.keys(localStorage)) if (key.startsWith('unsaid:token:') || key.startsWith('burned-words:token:')) localStorage.removeItem(key);
  } catch { /* fine */ }
}

async function request(url, { method = 'GET', body } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (session) headers['X-Session-Token'] = session;
  const res = await fetch(url, { method, headers, body: body && JSON.stringify(body) });
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

const api = (path, options) => request(`/api/worlds${path}`, options);
const authApi = (path, options) => request(`/api/auth${path}`, options);

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
  const spellingNote = { uk: ' · UK spelling', us: ' · US spelling' }[state.world.spelling] ?? '';
  $('world-name').textContent = state.world.name + spellingNote;
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
  const signedIn = Boolean(state.account);
  if (!signedIn && session) setSession(null); // The session expired or was signed out elsewhere.
  renderAccount(state.account);
  $('play-form').hidden = !signedIn || !state.prompt;
  $('signin').hidden = signedIn;

  renderSaid(state.said ?? [], state.prompt?.id);

  $('tab-misses').hidden = !joined;
  renderMisses(state.myMisses ?? [], state.prompt?.id);

  $('leaderboard').replaceChildren(...state.leaderboard.map((p) => el('tr', { className: p.nickname === state.me ? 'me' : '' },
    el('td', { textContent: p.nickname }),
    el('td', { textContent: p.words }),
    el('td', { textContent: p.thisPrompt }),
    el('td', { textContent: p.total }),
  )));
}

let lastStateLoaded = false;

// Earlier prompts the player has expanded, kept open across refreshes.
const openPrompts = new Set();

function saidList(words, emptyText) {
  return el('ol', { className: 'recent' }, ...(words.length ? words.map((r) => el('li', {},
    el('span', { className: 'said-word', textContent: r.word }),
    el('span', { className: 'muted', textContent: ` by ${r.by}${r.familyCount ? ` (+${r.familyCount} family)` : ''}` }),
    el('span', { className: 'points', textContent: `+${r.points}` }),
  )) : [el('li', { className: 'muted', textContent: emptyText })]));
}

const countText = (n) => `${n} said`;

// The current prompt's words in full, with earlier prompts collapsed underneath.
function renderSaid(groups, currentPromptId) {
  const parts = [];
  for (const group of groups) {
    if (group.promptId === currentPromptId) {
      parts.push(el('p', { className: 'group-title', textContent: `This prompt · ${countText(group.words.length)}` }));
      parts.push(saidList(group.words, 'Nothing said yet. Be the first.'));
    } else {
      const details = el('details', { className: 'earlier', open: openPrompts.has(group.promptId) },
        el('summary', {}, el('span', { textContent: group.prompt }), el('span', { className: 'muted', textContent: ` · ${countText(group.words.length)}` })),
        saidList(group.words, 'Nothing was said.'));
      details.addEventListener('toggle', () => (details.open ? openPrompts.add(group.promptId) : openPrompts.delete(group.promptId)));
      parts.push(details);
    }
  }
  const firstEarlier = parts.findIndex((part) => part.tagName === 'DETAILS');
  if (firstEarlier >= 0) parts.splice(firstEarlier, 0, el('p', { className: 'group-title earlier-title', textContent: 'Earlier prompts' }));
  $('recent').replaceChildren(...parts);
}

const MISS_REASONS = {
  'not-a-word': 'Not in the dictionary',
  'doesnt-fit': "Didn't fit",
  'already-burned': 'Already said',
  'not-in-category': 'Not on our list',
  'wrong-spelling': 'Other spelling',
};

// Words reported from the misses list on this device, so the button doesn't come back after a refresh.
const reported = new Set();

function renderMisses(misses, currentPromptId) {
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
    // Reports are for the current prompt, so only offer them while it's still running.
    if (isReportable(m) && m.promptId === currentPromptId) {
      rows.at(-1).append(reported.has(m.word) ? el('span', { className: 'muted small-note', textContent: 'reported' }) : reportButton(m.word, m.reason));
    }
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
  'wrong-spelling': (w, r) => `This world uses ${r.spelling === 'uk' ? 'UK' : 'US'} spelling${r.suggestions?.length ? `: try “${r.suggestions[0]}”` : ''}. No penalty.`,
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
    let result;
    try {
      result = await api(`/${encodeURIComponent(worldId)}/words`, { method: 'POST', body: { word } });
    } catch (err) {
      if (err.status === 401) { setSession(null); refresh(); } // Signed out elsewhere: show the sign-in box.
      throw err;
    }
    if (result.ok) {
      const family = result.alsoBurned.length ? ` Gone with it: ${result.alsoBurned.join(', ')}.` : '';
      feedback(`You said “${result.word}” for ${result.points} ${result.points === 1 ? 'point' : 'points'}. Nobody can say it again.${family}`, 'good');
      input.value = '';
    } else {
      feedback(REASONS[result.reason](result.word, result), 'bad');
      if (isReportable(result)) $('feedback').append(' ', reportButton(result.word, result.reason));
      input.select();
    }
    refresh();
  } catch (err) {
    feedback(err.message, 'bad');
  }
});

// Players can tell us about real answers missing from a meaning list, or real words missing
// from the dictionary. The admin page (/admin) reviews the reports.
const isReportable = (r) => r.reason === 'not-in-category' || (r.reason === 'not-a-word' && /^[a-z]{3,30}$/.test(r.word));

function reportButton(word, reason) {
  const text = reason === 'not-a-word' ? "Report it — it's a real word" : 'Report it — it should count';
  const button = el('button', { type: 'button', className: 'link', textContent: text });
  button.addEventListener('click', async () => {
    try {
      await api(`/${encodeURIComponent(worldId)}/reports`, { method: 'POST', body: { word } });
      reported.add(word);
      button.replaceWith('Thanks, reported.');
    } catch (err) {
      button.replaceWith(err.message);
    }
  });
  return button;
}

// --- Signing in ---

let signinEmail = '';

function showSigninStep(step) {
  for (const name of ['email', 'code', 'name']) $(`signin-${name}`).hidden = name !== step;
  $(`signin-${step}-input`)?.focus();
}

function renderAccount(account) {
  if (!account) {
    const button = el('button', { type: 'button', className: 'link', textContent: 'Sign in' });
    button.addEventListener('click', () => { showSigninStep('email'); $('signin').scrollIntoView({ block: 'center' }); });
    $('account').replaceChildren(button);
    return;
  }
  const out = el('button', { type: 'button', className: 'link', textContent: 'Sign out' });
  out.addEventListener('click', async () => {
    try { await authApi('/logout', { method: 'POST' }); } catch { /* signed out locally anyway */ }
    setSession(null);
    feedback('Signed out.', 'good');
    refresh();
  });
  $('account').replaceChildren(el('span', { className: 'muted', textContent: `${account.name} · ` }), out);
}

$('signin-email').addEventListener('submit', async (e) => {
  e.preventDefault();
  signinEmail = $('signin-email-input').value.trim();
  try {
    await authApi('/start', { method: 'POST', body: { email: signinEmail } });
    $('signin-email-shown').textContent = signinEmail;
    $('signin-code-input').value = '';
    showSigninStep('code');
    feedback('', '');
  } catch (err) {
    feedback(err.message, 'bad');
  }
});

$('signin-back').addEventListener('click', () => showSigninStep('email'));

async function verify(name) {
  const code = $('signin-code-input').value.trim();
  const result = await authApi('/verify', { method: 'POST', body: { email: signinEmail, code, name, claim: oldPlayerTokens() } });
  if (result.needsName) {
    showSigninStep('name');
    return;
  }
  setSession(result.token);
  forgetOldPlayerTokens();
  showSigninStep('email');
  feedback(`Welcome, ${result.user.name}! Start saying words.`, 'good');
  await refresh();
  $('word')?.focus();
}

$('signin-code').addEventListener('submit', async (e) => {
  e.preventDefault();
  try { await verify(null); } catch (err) { feedback(err.message, 'bad'); }
});

$('signin-name').addEventListener('submit', async (e) => {
  e.preventDefault();
  try { await verify($('signin-name-input').value); } catch (err) { feedback(err.message, 'bad'); }
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

$('new-world').addEventListener('click', () => {
  if (!session) {
    feedback('Sign in first to create a private world.', 'bad');
    showSigninStep('email');
    $('signin').hidden = false;
    $('signin').scrollIntoView({ block: 'center' });
    return;
  }
  $('new-world-dialog').showModal();
});
$('new-world-cancel').addEventListener('click', () => $('new-world-dialog').close());
$('new-world-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const spelling = new FormData(e.target).get('spelling');
  try {
    const world = await api('', { method: 'POST', body: { name: $('new-world-name').value, spelling } });
    location.href = `/w/${encodeURIComponent(world.id)}`;
  } catch (err) {
    $('new-world-dialog').close();
    feedback(err.message, 'bad');
  }
});

setInterval(refresh, POLL_MS);
refresh();
