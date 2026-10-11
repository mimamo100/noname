// How often to check for changes: every 3 seconds, slowing down when the world goes quiet
// or the page is in the background, where nobody is watching.
const POLL_MS = 3000;
const QUIET_POLL_MS = [6000, 12000]; // After a minute, then two minutes, with nothing new.
const HIDDEN_POLL_MS = 30000;
const worldId = location.pathname.startsWith('/w/') ? decodeURIComponent(location.pathname.slice(3)) : 'big';
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
const rootApi = (path, options) => request(`/api${path}`, options);
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

function renderClock(state) {
  if (state.prompt) $('ends-in').textContent = timeLeft(state.prompt.endsAt - state.now);
  if (state.world.kind === 'public' && state.world.endsAt) {
    $('round-info').textContent = `Everyone signed in plays here, and it starts afresh every round. This round ends in ${timeLeft(state.world.endsAt - state.now)}.`;
  }
}

// 1st, 2nd, 3rd, 4th… 11th, 12th, 13th… 21st
function ordinal(n) {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

function renderLobby(state) {
  const big = state.world.kind === 'public';
  $('lobby').hidden = !big;
  if (big) {
    const last = state.lastRound;
    $('last-round').hidden = !last;
    if (last) {
      $('last-round').replaceChildren(
        el('b', { textContent: 'Last round: ' }),
        last.winners.map((w) => `${ordinal(w.place)} ${w.nickname} (${w.score})`).join(' · '),
      );
    }
    $('room-button').textContent = state.myRoom ? `Back to ${state.myRoom.name}` : 'Join a room';
  }

  // A finished world: say why, and where to play next. Rooms are also only for their own players.
  const room = state.world.kind === 'room';
  const ended = state.world.ended;
  const notMine = room && !ended && !state.me;
  $('ended').hidden = !ended && !notMine;
  if (ended) {
    $('ended-message').textContent = state.world.endedMessage;
    $('ended-button').textContent = room ? 'Join a new room' : 'Go to the big world';
    $('ended-button').onclick = room ? joinRoom : () => { location.href = '/'; };
  } else if (notMine) {
    $('ended-message').textContent = "You can watch this room, but only its own players can say words. Join a room to play.";
    $('ended-button').textContent = 'Join a room';
    $('ended-button').onclick = joinRoom;
  }
}

async function joinRoom() {
  if (!session) {
    feedback('Sign in first to join a room.', 'bad');
    showSigninStep('email');
    $('signin').hidden = false;
    $('signin').scrollIntoView({ block: 'center' });
    return;
  }
  try {
    const room = await rootApi('/rooms', { method: 'POST' });
    location.href = `/w/${encodeURIComponent(room.id)}`;
  } catch (err) {
    feedback(err.message, 'bad');
  }
}
$('room-button').addEventListener('click', joinRoom);

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
    renderClock(state);
  } else {
    $('prompt-label').textContent = state.world.ended ? 'Finished' : 'This world has run out of words';
    $('remaining').textContent = '0';
    $('ends-in').textContent = '–';
  }
  $('copy-link').hidden = state.world.kind !== 'private';
  renderLobby(state);

  const joined = Boolean(state.me);
  const signedIn = Boolean(state.account);
  if (!signedIn && session) setSession(null); // The session expired or was signed out elsewhere.
  renderAccount(state.account);
  $('play-form').hidden = !signedIn || !state.prompt || (state.world.kind === 'room' && !joined);
  $('signin').hidden = signedIn;

  renderSaid(state.said ?? [], state.prompt?.id);

  $('tab-misses').hidden = !joined;
  renderMisses(state.myMisses ?? [], state.prompt?.id);

  $('leaderboard').replaceChildren(...boardRows(state.leaderboard, 'total', state.me));
  const promptBoard = state.promptBoard ?? [];
  $('prompt-board').replaceChildren(...boardRows(promptBoard, 'points', state.me));
  $('prompt-board-empty').hidden = promptBoard.length > 0;
}

// Leaderboard rows with positions; players on the same score share a position.
function boardRows(players, scoreKey, me) {
  let rank = 0;
  return players.map((p, i) => {
    if (i === 0 || p[scoreKey] !== players[i - 1][scoreKey]) rank = i + 1;
    return el('tr', { className: p.nickname === me ? 'me' : '' },
      el('td', { className: 'rank', textContent: rank }),
      el('td', {}, p.nickname, ...(p.away ? [' ', el('span', { className: 'away', textContent: '(away)' })] : [])),
      el('td', { textContent: p.words }),
      el('td', { textContent: p[scoreKey] }),
    );
  });
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

// Words reported in this visit, until the server's next update includes the report.
const reported = new Set();
const REPORT_STATUS = {
  reported: 'Reported: waiting for a decision',
  dismissed: "Report reviewed: it doesn't count",
};

// An accepted word may have been used up since, by the player or someone else.
function reportStatusText(status, m, currentPromptId) {
  if (status !== 'accepted') return REPORT_STATUS[status];
  const said = m.said;
  if (!said) return m.promptId === currentPromptId ? 'Report accepted: it counts now, so try it again' : 'Report accepted: it counts from now on';
  if (said.byYou && said.promptId === m.promptId) return "Report accepted: you've said it since";
  const who = said.byYou ? 'you' : said.by;
  return `Report accepted, but it's used up: ${who} said it for “${said.prompt}”`;
}

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
    const status = m.reportStatus ?? (reported.has(m.word) ? 'reported' : null);
    if (status) {
      rows.at(-1).append(el('span', { className: 'muted small-note', textContent: reportStatusText(status, m, currentPromptId) }));
    } else if (isReportable(m) && m.promptId === currentPromptId) {
      rows.at(-1).append(reportButton(m.word, m.reason));
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
function showBoard(name) {
  const prompt = name === 'prompt';
  $('tab-overall').setAttribute('aria-selected', String(!prompt));
  $('tab-prompt').setAttribute('aria-selected', String(prompt));
  $('board-overall').hidden = prompt;
  $('board-prompt').hidden = !prompt;
  try { localStorage.setItem('unsaid:board', name); } catch { /* not saved: fine */ }
}
$('tab-overall').addEventListener('click', () => showBoard('overall'));
$('tab-prompt').addEventListener('click', () => showBoard('prompt'));
try { if (localStorage.getItem('unsaid:board') === 'prompt') showBoard('prompt'); } catch { /* default board */ }

$('tab-recent').addEventListener('click', () => showTab('recent'));
$('tab-misses').addEventListener('click', () => showTab('misses'));

// The last full state, and how many polls in a row have found nothing new.
let lastState = null;
let quietPolls = 0;
let refreshCount = 0;

async function refresh() {
  const thisRefresh = ++refreshCount;
  try {
    // The server answers just "unchanged" if nothing is new since the version we have.
    const since = lastState ? `?since=${encodeURIComponent(lastState.version)}` : '';
    const state = await api(`/${encodeURIComponent(worldId)}${since}`);
    if (thisRefresh !== refreshCount) return; // A newer refresh started meanwhile: let it win.
    if (state.unchanged && lastState) {
      quietPolls++;
      lastState.now = state.now;
      renderClock(lastState);
      return;
    }
    quietPolls = 0;
    lastState = state;
    render(state);
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

const shortDate = (ms) => new Date(ms).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const penaltyText = (r) => (r.penalty ? ` −${r.penalty} ${r.penalty === 1 ? 'point' : 'points'}.` : '');

const REASONS = {
  'not-a-word': (w) => `“${w}” isn't in the dictionary.`,
  'doesnt-fit': (w, r) => `“${w}” doesn't fit the prompt.${penaltyText(r)}`,
  'not-in-category': (w, r) => `“${w}” isn't on our list for “${r.category}”. No penalty.`,
  'wrong-spelling': (w, r) => `This world uses ${r.spelling === 'uk' ? 'UK' : 'US'} spelling${r.suggestions?.length ? `: try “${r.suggestions[0]}”` : ''}. No penalty.`,
  'already-burned': (w, r) => r.burned
    ? (r.byYou
      ? `You already said “${r.burned.playedWord}”${r.burned.playedWord !== w ? ` (which uses up “${w}”)` : ''} on ${shortDate(r.burned.at)}, for “${r.burned.prompt}”. No penalty.`
      : `“${w}” has already been said — by ${r.burned.by}${r.burned.playedWord !== w ? ` (with “${r.burned.playedWord}”)` : ''} on ${shortDate(r.burned.at)}, for “${r.burned.prompt}”.${penaltyText(r)}`)
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
  const games = el('button', { type: 'button', className: 'link', textContent: 'My games' });
  games.addEventListener('click', showHistory);
  $('account').replaceChildren(el('span', { className: 'muted', textContent: `${account.name} · ` }), games, el('span', { className: 'muted', textContent: ' · ' }), out);
}

async function showHistory() {
  $('history').replaceChildren(el('p', { className: 'muted', textContent: 'Loading…' }));
  $('history-dialog').showModal();
  try {
    const { bests, games } = await rootApi('/me/history');
    if (!games.length) {
      $('history').replaceChildren(el('p', { className: 'muted', textContent:
        `No finished games yet. Big world rounds finish at the end of each round, and rooms when their words run out.${bests.wordsSaid ? ` So far you've said ${bests.wordsSaid.toLocaleString()} words.` : ''}` }));
      return;
    }
    const best = (value, label) => el('div', {}, el('b', { textContent: value }), el('span', { textContent: label }));
    $('history').replaceChildren(
      el('div', { className: 'bests' },
        best(bests.games, bests.games === 1 ? 'game' : 'games'),
        best(bests.wins, bests.wins === 1 ? 'win' : 'wins'),
        best(ordinal(bests.bestPlace), 'best finish'),
        best(bests.bestScore, 'best score'),
        best(bests.mostWords, 'most words in a game'),
        best(bests.wordsSaid.toLocaleString(), 'words said in all'),
        ...(bests.bestWord ? [best(bests.bestWord.word, `best word (+${bests.bestWord.points})`)] : []),
      ),
      el('ol', { className: 'history-list' }, ...games.map((g) => el('li', {},
        el('a', { href: `/w/${encodeURIComponent(g.worldId)}`, textContent: g.name }),
        ' ', el('span', { className: 'when', textContent: shortDate(g.endedAt) }),
        el('div', { textContent: [
          `${ordinal(g.place)} of ${g.players}`,
          `${g.score} points`,
          `${g.words} ${g.words === 1 ? 'word' : 'words'}`,
          ...(g.bestWord ? [`best: ${g.bestWord} (+${g.bestPoints})`] : []),
        ].join(' · ') }),
      ))),
    );
  } catch (err) {
    $('history').replaceChildren(el('p', { className: 'bad', textContent: err.message }));
  }
}
$('history-close').addEventListener('click', () => $('history-dialog').close());

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

function pollDelay() {
  if (document.hidden) return HIDDEN_POLL_MS;
  if (quietPolls >= 40) return QUIET_POLL_MS[1];
  if (quietPolls >= 20) return QUIET_POLL_MS[0];
  return POLL_MS;
}

let pollTimer;
function schedulePoll() {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    await refresh();
    schedulePoll();
  }, pollDelay());
}

// Back to the page: catch up at once, then poll at full speed again.
document.addEventListener('visibilitychange', () => {
  if (document.hidden) return;
  quietPolls = 0;
  refresh();
  schedulePoll();
});

refresh();
schedulePoll();
