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

// A private world's invite link carries a code, which every request for the world passes on.
const invite = new URLSearchParams(location.search).get('invite');
const withInvite = (url) => (invite ? `${url}${url.includes('?') ? '&' : '?'}invite=${encodeURIComponent(invite)}` : url);
const api = (path, options) => request(withInvite(`/api/worlds${path}`), options);
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
  if (state.world.kind === 'competition') renderCompetitionClock(state);
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

// --- Competitions ---

const whenText = (ms) => new Date(ms).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
let competitionShown = false;

function renderCompetitionClock(state) {
  const line = document.getElementById('competition-clock');
  if (!line || state.world.ended) return;
  const started = state.now >= state.world.startsAt;
  line.textContent = started
    ? `It's on: ends in ${timeLeft(state.world.endsAt - state.now)}.`
    : `Starts in ${timeLeft(state.world.startsAt - state.now)} (${whenText(state.world.startsAt)}), ends ${whenText(state.world.endsAt)}.`;
}

function renderCompetition(state) {
  const competition = state.world.kind === 'competition';
  $('tab-teams').hidden = !competition;
  $('competition').hidden = !competition || state.world.ended;
  if (!competition) {
    if (board === 'teams') showBoard('overall', false);
    competitionShown = false;
    return;
  }
  if (!competitionShown) {
    competitionShown = true;
    let saved = null;
    try { saved = localStorage.getItem('unsaid:board'); } catch { /* default */ }
    showBoard(saved === 'prompt' || saved === 'overall' ? saved : 'teams', false);
  }
  if (state.world.ended) return;
  const started = state.now >= state.world.startsAt;
  const parts = [el('p', { id: 'competition-clock' })];
  if (state.myTeam) {
    parts.push(el('p', {}, "You're in ", ...teamDot(state.myTeam.position), el('b', { textContent: state.myTeam.name }),
      started ? '. Every point you score counts for your team.' : '. Send teammates your team link (Copy team link, at the top).'));
  } else if (state.joiningTeam) {
    const join = el('button', { type: 'button', textContent: `Join ${state.joiningTeam.name}` });
    join.addEventListener('click', async () => {
      if (!session) { showSigninStep('email'); $('signin').hidden = false; $('signin').scrollIntoView({ block: 'center' }); return; }
      try {
        await api(`/${encodeURIComponent(worldId)}/join`, { method: 'POST' });
        feedback(`You've joined ${state.joiningTeam.name}.`, 'good');
        refresh();
      } catch (err) { feedback(err.message, 'bad'); }
    });
    parts.push(el('p', {}, "This is the link for ", ...teamDot(state.joiningTeam.position), el('b', { textContent: state.joiningTeam.name }), '. '), join);
  } else if (!state.isOwner) {
    parts.push(el('p', { className: 'muted small', textContent: "You're watching. To play, open your team's invite link from the organiser." }));
  }
  if (!started) {
    parts.push(el('ul', { className: 'teams-forming' }, ...state.teams.slice().sort((a, b) => a.position - b.position).map((t) => el('li', {},
      ...teamDot(t.position), el('b', { textContent: t.name }), ' ',
      el('span', { className: 'muted small', textContent: t.players.length ? t.players.join(', ') : 'nobody yet' }),
    ))));
  }
  $('competition').replaceChildren(...parts);
  renderCompetitionClock(state);
}

// Times for datetime-local inputs, in the viewer's own time zone.
const toLocalInput = (ms) => { const d = new Date(ms); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 16); };
const fromLocalInput = (value) => new Date(value).getTime();

$('new-competition').addEventListener('click', () => {
  const start = new Date();
  start.setHours(start.getHours() + 1, 0, 0, 0);
  $('comp-starts').value = toLocalInput(start.getTime());
  $('comp-ends').value = toLocalInput(start.getTime() + 3_600_000);
  $('comp-dialog').showModal();
});
$('comp-cancel').addEventListener('click', () => $('comp-dialog').close());
$('comp-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const made = await rootApi('/competitions', { method: 'POST', body: {
      name: $('comp-name').value,
      startsAt: fromLocalInput($('comp-starts').value),
      endsAt: fromLocalInput($('comp-ends').value),
      promptMinutes: Number($('comp-pace').value),
      teams: $('comp-teams').value.split('\n'),
      teamSize: $('comp-size').value ? Number($('comp-size').value) : null,
      spelling: new FormData(e.target).get('comp-spelling'),
    } });
    location.href = `/w/${encodeURIComponent(made.id)}`;
  } catch (err) {
    feedback(err.message, 'bad');
    $('comp-dialog').close();
  }
});

let compSettings = null;
const compUrl = (code) => `${location.origin}/w/${encodeURIComponent(lastState.world.id)}?invite=${encodeURIComponent(code)}`;

async function compAction(action, body, done, question) {
  if (question && !confirm(question)) return;
  try {
    const result = await api(`/${encodeURIComponent(lastState.world.id)}/${action}`, { method: 'POST', body });
    if (action === 'delete') { location.href = '/'; return; }
    await loadCompSettings();
    if (done) feedback(done, 'good');
    refresh();
    return result;
  } catch (err) {
    feedback(err.message, 'bad');
    alert(err.message);
  }
}

async function loadCompSettings() {
  const c = compSettings = await api(`/${encodeURIComponent(lastState.world.id)}/settings`);
  $('cs-name').value = c.name;
  $('cs-starts').value = toLocalInput(c.startsAt);
  $('cs-ends').value = toLocalInput(c.endsAt);
  $('cs-pace').value = String(c.promptMinutes);
  $('cs-size').value = c.teamSize ?? '';
  for (const id of ['cs-starts', 'cs-pace', 'cs-size']) $(id).disabled = c.started;
  $('cs-started-note').hidden = !c.started;
  $('cs-add-team').hidden = c.started || c.ended;
  $('cs-watch').textContent = compUrl(c.watchInvite);
  $('cs-end').disabled = c.ended;
  $('cs-delete').disabled = !c.canDelete;
  $('cs-teams').replaceChildren(...c.teams.map((t) => {
    const name = el('input', { value: t.name, maxLength: 30 });
    const rename = el('button', { type: 'button', className: 'secondary', textContent: 'Rename' });
    rename.addEventListener('click', () => compAction('teams', { rename: t.id, name: name.value }, 'Team renamed.'));
    const copy = el('button', { type: 'button', className: 'secondary', textContent: 'Copy link' });
    copy.addEventListener('click', () => copyText(compUrl(t.invite), `${t.name}'s link copied.`));
    const fresh = el('button', { type: 'button', className: 'link', textContent: 'New link' });
    fresh.addEventListener('click', () => compAction('invite', { team: t.id }, `New link for ${t.name}.`, `Make a new link for ${t.name}? The old one stops working.`));
    const head = [...teamDot(t.position), name, rename, copy, fresh];
    if (!c.started && !t.players.length) {
      const remove = el('button', { type: 'button', className: 'link', textContent: 'Remove team' });
      remove.addEventListener('click', () => compAction('teams', { remove: t.id }, `${t.name} removed.`, `Remove ${t.name}?`));
      head.push(remove);
    }
    return el('div', { className: 'cs-team' },
      el('div', { className: 'cs-team-head' }, ...head),
      el('ul', {}, ...(t.players.length ? t.players.map((p) => {
        const actions = [];
        if (!c.started) {
          const move = el('select', {}, el('option', { value: '', textContent: 'Move to…' }),
            ...c.teams.filter((o) => o.id !== t.id).map((o) => el('option', { value: o.id, textContent: o.name })));
          move.addEventListener('change', () => move.value && compAction('move', { nickname: p.nickname, team: Number(move.value) }, `${p.nickname} moved.`));
          actions.push(move);
        }
        const remove = el('button', { type: 'button', className: 'link', textContent: 'Remove' });
        remove.addEventListener('click', () => compAction('remove', { nickname: p.nickname }, `${p.nickname} removed.`,
          `Remove ${p.nickname}? Their words stay used up, but their points leave the team and they can't come back.`));
        actions.push(remove);
        return el('li', {}, el('span', {}, el('b', { textContent: p.nickname }), ` ${p.score} points`), el('span', {}, ...actions));
      }) : [el('li', { className: 'muted', textContent: 'Nobody yet' })])),
    );
  }));
}

$('comp-settings-form').addEventListener('submit', (e) => {
  e.preventDefault();
  const body = { name: $('cs-name').value, endsAt: fromLocalInput($('cs-ends').value) };
  if (!compSettings.started) {
    Object.assign(body, { startsAt: fromLocalInput($('cs-starts').value), promptMinutes: Number($('cs-pace').value),
      teamSize: $('cs-size').value ? Number($('cs-size').value) : null });
  }
  compAction('settings', body, 'Settings saved.');
});
$('cs-add-team').addEventListener('submit', (e) => {
  e.preventDefault();
  compAction('teams', { add: $('cs-new-team').value }, 'Team added.').then(() => { $('cs-new-team').value = ''; });
});
$('cs-copy-watch').addEventListener('click', () => copyText(compUrl(compSettings.watchInvite), 'Watching link copied.'));
$('cs-end').addEventListener('click', () => compAction('end', {}, 'The competition has ended.', 'End the competition now for everyone? Final standings are as they are now. This can\'t be undone.'));
$('cs-delete').addEventListener('click', () => compAction('delete', {}, '', 'Delete this competition and everything in it? This can\'t be undone.'));
$('cs-close').addEventListener('click', () => $('comp-settings-dialog').close());

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

  // A competition before its start has no prompt yet: just say so, without empty counters.
  const waiting = state.world.kind === 'competition' && !state.world.ended && state.now < state.world.startsAt;
  document.querySelector('.prompt-card').classList.toggle('waiting', waiting);
  if (state.prompt) {
    $('prompt-label').textContent = state.prompt.label;
    $('remaining').textContent = state.prompt.remaining;
    const used = 1 - state.prompt.remaining / state.prompt.availableAtStart;
    $('meter-fill').style.width = `${Math.round(used * 100)}%`;
    renderClock(state);
  } else {
    $('prompt-label').textContent = state.world.ended ? 'Finished' : waiting ? 'The first prompt appears at the start' : 'This world has run out of words';
    $('remaining').textContent = '0';
    $('ends-in').textContent = '–';
  }
  const competition = state.world.kind === 'competition';
  $('copy-link').hidden = !state.invite;
  $('copy-link').textContent = competition ? 'Copy team link' : 'Copy invite link';
  $('world-settings').hidden = !state.isOwner;
  $('world-settings').textContent = competition ? 'Competition settings' : 'World settings';
  $('new-competition').hidden = state.account?.plan !== 'organiser';
  renderCompetition(state);
  renderLobby(state);

  const joined = Boolean(state.me);
  const signedIn = Boolean(state.account);
  if (!signedIn && session) setSession(null); // The session expired or was signed out elsewhere.
  renderAccount(state.account, state.admin);
  // In a competition, only team members (or someone who opened a team's link) can play.
  const watching = state.world.kind === 'competition' && !state.myTeam && !state.joiningTeam;
  $('play-form').hidden = !signedIn || !state.prompt || (state.world.kind === 'room' && !joined) || watching;
  $('signin').hidden = signedIn;

  renderSaid(state.said ?? [], state.prompt?.id);

  $('tab-misses').hidden = !joined;
  renderMisses(state.myMisses ?? [], state.prompt?.id);

  $('leaderboard').replaceChildren(...boardRows(state.leaderboard, 'total', state.me));
  const promptBoard = state.promptBoard ?? [];
  $('prompt-board').replaceChildren(...boardRows(promptBoard, 'points', state.me));
  $('prompt-board-empty').hidden = promptBoard.length > 0;
  if (state.teams) {
    let rank = 0;
    $('team-board').replaceChildren(...state.teams.map((t, i) => {
      if (i === 0 || t.score !== state.teams[i - 1].score) rank = i + 1;
      return el('tr', { className: state.myTeam?.name === t.name ? 'me' : '' },
        el('td', { className: 'rank', textContent: rank }),
        el('td', {}, ...teamDot(t.position), t.name),
        el('td', { textContent: t.players.length }),
        el('td', { textContent: t.thisPrompt }),
        el('td', { textContent: t.score }),
      );
    }));
  }
}

// A competition team's colour, by its position.
const teamDot = (position) => (position === undefined || position === null ? [] : [el('span', { className: `team-dot team-${position % 10}` })]);

// Leaderboard rows with positions; players on the same score share a position.
function boardRows(players, scoreKey, me) {
  let rank = 0;
  return players.map((p, i) => {
    if (i === 0 || p[scoreKey] !== players[i - 1][scoreKey]) rank = i + 1;
    return el('tr', { className: p.nickname === me ? 'me' : '' },
      el('td', { className: 'rank', textContent: rank }),
      el('td', {}, ...teamDot(p.team), p.nickname, ...(p.away ? [' ', el('span', { className: 'away', textContent: '(away)' })] : [])),
      el('td', { textContent: p.words }),
      el('td', { textContent: p[scoreKey] }),
    );
  });
}

let lastStateLoaded = false;

// Earlier prompts the player has expanded, kept open across refreshes.
const openPrompts = new Set();

// Masked words (mild rude words) show as p***s until tapped, so the page stays clean at a
// glance but anyone can check before trying one. Tapped words stay shown across refreshes.
const revealed = new Set();
const mask = (word) => word[0] + '*'.repeat(word.length - 2) + word.at(-1);

function saidWord(r) {
  if (!r.masked || revealed.has(r.word)) return el('span', { className: 'said-word', textContent: r.word });
  const span = el('span', { className: 'said-word masked', textContent: mask(r.word), title: 'Tap to show the word', tabIndex: 0, role: 'button' });
  const show = () => {
    revealed.add(r.word);
    span.replaceWith(el('span', { className: 'said-word', textContent: r.word }));
  };
  span.addEventListener('click', show);
  span.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(); } });
  return span;
}

function saidList(words, emptyText) {
  return el('ol', { className: 'recent' }, ...(words.length ? words.map((r) => el('li', {},
    saidWord(r),
    el('span', { className: 'muted' }, ' by ', ...teamDot(r.team), `${r.by}${r.familyCount ? ` (+${r.familyCount} family)` : ''}`),
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
// Leaderboard tabs: Teams (competitions only), Overall and This prompt.
let board = 'overall';
function showBoard(name, remember = true) {
  if (name === 'teams' && $('tab-teams').hidden) name = 'overall';
  board = name;
  for (const b of ['teams', 'overall', 'prompt']) {
    $(`tab-${b}`).setAttribute('aria-selected', String(b === name));
    $(`board-${b}`).hidden = b !== name;
  }
  if (remember) try { localStorage.setItem('unsaid:board', name); } catch { /* not saved: fine */ }
}
for (const b of ['teams', 'overall', 'prompt']) $(`tab-${b}`).addEventListener('click', () => showBoard(b));
try { if (localStorage.getItem('unsaid:board') === 'prompt') showBoard('prompt', false); } catch { /* default board */ }

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
    $('prompt-label').textContent = err.status === 404 && err.message === 'World not found' ? 'World not found'
      : err.status === 403 ? 'Private world' : 'Something went wrong';
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
      : `“${w}” has already been said — by ${r.burned.by}${r.burned.playedWord !== w ? ` (with “${r.burned.playedWord}”)` : ''} on ${shortDate(r.burned.at)}, for “${r.burned.prompt}”.${r.penalty ? penaltyText(r) : ' No penalty, as it was hidden.'}`)
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
      button.replaceWith('Thanks, reported. My misses will show what happens to it.');
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

function renderAccount(account, adminUrl) {
  const admin = adminUrl ? [el('span', { className: 'muted', textContent: ' · ' }), el('a', { href: adminUrl, className: 'admin-link', textContent: 'Admin' })] : [];
  if (!account) {
    const button = el('button', { type: 'button', className: 'link', textContent: 'Sign in' });
    button.addEventListener('click', () => { showSigninStep('email'); $('signin').scrollIntoView({ block: 'center' }); });
    $('account').replaceChildren(button, ...admin);
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
  const worlds = el('button', { type: 'button', className: 'link', textContent: 'My worlds' });
  worlds.addEventListener('click', showWorlds);
  const dot = () => el('span', { className: 'muted', textContent: ' · ' });
  $('account').replaceChildren(el('span', { className: 'muted', textContent: account.name }), dot(), worlds, dot(), games, dot(), out, ...admin);
}

function ago(ms) {
  const mins = Math.floor((Date.now() - ms) / 60000);
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  if (mins < 48 * 60) return `${Math.floor(mins / 60)}h ago`;
  return `${Math.floor(mins / 1440)} days ago`;
}

async function showWorlds() {
  const big = el('li', {}, el('a', { href: '/', textContent: 'The Big World' }), el('div', { className: 'muted small', textContent: 'Everyone signed in, afresh every round' }));
  $('worlds-list').replaceChildren(big, el('li', { className: 'muted', textContent: 'Loading…' }));
  $('worlds-dialog').showModal();
  try {
    const { worlds } = await rootApi('/me/worlds');
    $('worlds-list').replaceChildren(big, ...worlds.map((w) => el('li', {},
      el('a', { href: `/w/${encodeURIComponent(w.id)}`, textContent: w.name }),
      ' ', el('span', { className: 'when', textContent: (w.kind === 'room' ? 'room' : w.kind === 'competition' ? 'competition' : 'private') + (w.isOwner ? ' · yours' : '') }),
      el('div', { className: 'small', textContent: w.ended ? 'Ended. Open it to delete it, or see it in My games.' : [
        ...(w.kind === 'competition' && w.startsAt > Date.now() ? [`starts ${whenText(w.startsAt)}`] : []),
        ...(w.kind === 'competition' && w.startsAt <= Date.now() ? [`ends in ${timeLeft(w.endsAt - Date.now())}`] : []),
        `${w.players} ${w.players === 1 ? 'player' : 'players'}`,
        `${Math.round(w.left * 100)}% left`,
        ...(w.score !== null ? [`you: ${w.score} points`] : []),
        w.lastPlayed ? `played ${ago(w.lastPlayed)}` : 'not played yet',
      ].join(' · ') }),
    )), ...(worlds.length ? [] : [el('li', { className: 'muted', textContent: 'No rooms or private worlds yet. Join a room from the big world, or open an invite link.' })]));
  } catch (err) {
    $('worlds-list').replaceChildren(big, el('li', { className: 'bad', textContent: err.message }));
  }
}
$('worlds-close').addEventListener('click', () => $('worlds-dialog').close());

// --- World settings, for a private world's owner ---

let settings = null;

async function loadSettings() {
  settings = await api(`/${encodeURIComponent(lastState.world.id)}/settings`);
  $('settings-name').value = settings.name;
  for (const radio of document.querySelectorAll('input[name="settings-pace"]')) radio.checked = Number(radio.value) === settings.promptHours;
  $('settings-max').value = settings.maxPlayers ?? '';
  $('settings-closed').checked = settings.closed;
  $('settings-invite').textContent = inviteUrl(settings.invite);
  $('settings-end').disabled = settings.ended;
  $('settings-delete').disabled = !settings.canDelete;
  $('settings-players').replaceChildren(...settings.players.map((p) => {
    const row = el('li', {}, el('b', { textContent: p.nickname }), ' ',
      el('span', { className: 'when', textContent: `${p.words} words · ${p.score} points · last played ${ago(p.lastActive)}` }));
    if (!p.isYou) {
      const remove = el('button', { type: 'button', className: 'link row-actions', textContent: 'Remove' });
      remove.addEventListener('click', () => settingsAction(`Remove ${p.nickname}? Their words stay used up, but they leave the leaderboard and can't come back.`,
        'remove', { nickname: p.nickname }, `${p.nickname} has been removed.`));
      row.prepend(remove);
    }
    return row;
  }));
}

async function settingsAction(question, action, body, done) {
  if (question && !confirm(question)) return;
  try {
    const result = await api(`/${encodeURIComponent(lastState.world.id)}/${action}`, { method: 'POST', body });
    if (action === 'delete') { location.href = '/'; return; }
    await loadSettings();
    feedback(done, 'good');
    refresh();
    return result;
  } catch (err) {
    feedback(err.message, 'bad');
  }
}

$('world-settings').addEventListener('click', async () => {
  try {
    if (lastState.world.kind === 'competition') {
      await loadCompSettings();
      $('comp-settings-dialog').showModal();
      return;
    }
    await loadSettings();
    $('settings-dialog').showModal();
  } catch (err) {
    feedback(err.message, 'bad');
  }
});
$('settings-close').addEventListener('click', () => $('settings-dialog').close());
$('settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const pace = document.querySelector('input[name="settings-pace"]:checked');
  await settingsAction(null, 'settings', {
    name: $('settings-name').value,
    promptHours: Number(pace.value),
    maxPlayers: $('settings-max').value ? Number($('settings-max').value) : null,
    closed: $('settings-closed').checked,
  }, 'Settings saved.');
});
$('settings-copy').addEventListener('click', () => copyText(inviteUrl(settings.invite), 'Invite link copied.'));
$('settings-new-invite').addEventListener('click', () => settingsAction('Make a new invite link? The old one will stop working.', 'invite', {}, 'New invite link made. Send it to anyone still to join.'));
$('settings-end').addEventListener('click', () => settingsAction('End this world for everyone? Final standings go into everyone\'s My games. This can\'t be undone.', 'end', {}, 'The world has ended.'));
$('settings-delete').addEventListener('click', () => settingsAction('Delete this world and everything in it? This can\'t be undone.', 'delete', {}, ''));

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
        ...(g.team ? [el('div', {}, 'Team ', el('b', { textContent: g.team.name }), g.team.place ? `: ${ordinal(g.team.place)} of ${g.team.teams} teams` : '')] : []),
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

const inviteUrl = (code) => `${location.origin}/w/${encodeURIComponent(lastState.world.id)}?invite=${encodeURIComponent(code)}`;

async function copyText(text, done) {
  try {
    await navigator.clipboard.writeText(text);
    feedback(done, 'good');
  } catch {
    feedback(`Share this link: ${text}`, 'good');
  }
}
$('copy-link').addEventListener('click', () => copyText(inviteUrl(lastState.invite), 'Invite link copied. Send it to your friends!'));

$('new-world').addEventListener('click', () => {
  if (session && lastState?.account && lastState.account.plan === 'free') {
    feedback('Creating private worlds needs the Host plan. You can join anyone\'s private world with their invite link.', 'bad');
    return;
  }
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
    const promptHours = Number(new FormData(e.target).get('pace'));
    const maxPlayers = $('new-world-max').value ? Number($('new-world-max').value) : null;
    const world = await api('', { method: 'POST', body: { name: $('new-world-name').value, spelling, promptHours, maxPlayers } });
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
