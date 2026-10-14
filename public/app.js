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
  if (state.prompt) {
    // A prompt ends when enough of its answers are said, or when its time runs out.
    const more = state.prompt.remaining - state.prompt.endsWhenLeft;
    $('ends-text').replaceChildren('Ends after ', el('b', { textContent: `${more} more` }), ' or in ', el('b', { id: 'ends-in', textContent: timeLeft(state.prompt.endsAt - state.now) }));
  }
  if (state.world.kind === 'competition') renderCompetitionClock(state);
  if (state.world.kind === 'public' && state.world.endsAt) {
    $('round-ends').textContent = timeLeft(state.world.endsAt - state.now);
  }
}

// 1st, 2nd, 3rd, 4th… 11th, 12th, 13th… 21st
function ordinal(n) {
  const s = ['th', 'st', 'nd', 'rd'];
  const v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

// --- Sharing a result ---

const MEDALS = { 1: '🥇', 2: '🥈', 3: '🥉' };

function shareText(g) {
  const lines = [
    `Unsaid · ${g.name}`,
    `${MEDALS[g.place] ? `${MEDALS[g.place]} ` : ''}${ordinal(g.place)} of ${g.players} · ${g.score} points · ${g.words} ${g.words === 1 ? 'word' : 'words'}`,
  ];
  if (g.team) lines.push(`Team ${g.team.name}${g.team.place ? `: ${ordinal(g.team.place)} of ${g.team.teams}` : ''}`);
  if (g.bestWord) lines.push(`Best word: ${g.bestMasked ? mask(g.bestWord) : g.bestWord} (+${g.bestPoints})`);
  // For someone who has never heard of it.
  lines.push('', 'Unsaid is a word game where every word can only be said once. Have a go:');
  return lines.join('\n');
}

// The subject when shared by email, and the share sheet's title.
const shareTitle = (g) => `Unsaid: my result in ${g.name}`;

// Anyone can watch the big world's rounds and rooms; private worlds and competitions need a link, so send people to the game itself.
const shareUrl = (g) => (g.kind === 'public' || g.kind === 'room' ? `${location.origin}/w/${encodeURIComponent(g.worldId)}` : `${location.origin}/`);

// Phones get the share sheet; elsewhere the result is copied, and the button says so.
async function shareResult(g, button) {
  const text = shareText(g);
  const url = shareUrl(g);
  if (navigator.share && matchMedia('(pointer: coarse)').matches) {
    try { await navigator.share({ title: shareTitle(g), text, url }); return; } catch (err) { if (err.name === 'AbortError') return; }
  }
  try {
    await navigator.clipboard.writeText(`${text}\n${url}`);
    const label = button.textContent;
    button.textContent = 'Copied: paste it anywhere';
    setTimeout(() => { button.textContent = label; }, 2500);
  } catch {
    prompt('Copy your result:', `${text}\n${url}`);
  }
}

function shareButton(g, label = 'Share') {
  const button = el('button', { type: 'button', className: 'secondary small share', textContent: label });
  button.addEventListener('click', () => shareResult(g, button));
  return button;
}

const resultLine = (g) => `${MEDALS[g.place] ? `${MEDALS[g.place]} ` : ''}${ordinal(g.place)} of ${g.players} with ${g.score} points`;

function renderLobby(state) {
  const big = state.world.kind === 'public';
  $('lobby').hidden = !big;
  if (big) {
    const hours = state.roundHours ?? 24;
    $('round-about').textContent = `Everyone signed in plays here, and it starts afresh ${hours === 24 ? 'every day' : `every ${hours} ${hours === 1 ? 'hour' : 'hours'}`}.`;
    const last = state.lastRound;
    $('last-round').hidden = !last;
    if (last) {
      $('last-round').replaceChildren(
        el('h3', { textContent: 'Last round' }),
        el('ol', { className: 'winners' }, ...last.winners.map((w) => el('li', {},
          el('span', { textContent: `${MEDALS[w.place] ?? ordinal(w.place)} ${w.nickname}` }),
          el('span', { className: 'muted', textContent: points(w.score) })))),
        ...(last.mine ? [el('p', { className: 'small' }, `You came ${resultLine(last.mine)}. `, shareButton(last.mine, 'Share your result'))] : []),
      );
    }
    $('room-button').textContent = state.myRoom ? `Back to ${state.myRoom.name}` : 'Join a room';
  }

  // A finished world: say why, and where to play next. Rooms are also only for their own players.
  const room = state.world.kind === 'room';
  const ended = state.world.ended;
  const notMine = room && !ended && !state.me;
  $('ended').hidden = !ended && !notMine;
  // The results card (in place of the prompt card) shows the player's own result now.
  $('ended-result').hidden = true;
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

// --- Results, when a world finishes ---

const points = (n) => `${n.toLocaleString()} ${Math.abs(n) === 1 ? 'point' : 'points'}`;

function renderResults(state) {
  const board = state.leaderboard ?? [];
  const teams = state.teams ?? null;
  const parts = [el('p', { className: 'eyebrow', textContent: 'Final result' })];
  let iWon = false;
  if (teams && teams.length) {
    // Teams, best first: a winner, or a draw at the top.
    const [first, second] = teams;
    const draw = second && second.score === first.score;
    parts.push(el('h1', { textContent: draw ? "It's a draw!" : `${first.name} win!` }));
    parts.push(el('p', { className: 'result-score', textContent: teams.map((t) => `${t.name} ${t.score.toLocaleString()}`).join(' · ') }));
    if (!draw && first.players.length) parts.push(el('p', { className: 'muted' }, `Well played ${first.players.join(', ')}.`));
    iWon = !draw && state.myTeam?.name === first.name;
  } else if (board.length) {
    const top = board.filter((p) => p.total === board[0].total);
    parts.push(el('h1', { textContent: top.length > 1 ? `${top.map((p) => p.nickname).join(' and ')} share first place!` : `${board[0].nickname} wins!` }));
    parts.push(el('p', { className: 'result-score', textContent: `with ${points(board[0].total)}, out of ${board.length} ${board.length === 1 ? 'player' : 'players'}` }));
    iWon = top.some((p) => p.nickname === state.me);
  } else {
    parts.push(el('h1', { textContent: 'Finished' }));
  }
  if (teams && board.length) parts.push(el('p', { className: 'muted' }, `Top scorer: ${board[0].nickname}, ${points(board[0].total)}.`));
  const mine = state.myResult;
  if (mine) {
    const team = mine.team?.place ? ` ${mine.team.name} came ${ordinal(mine.team.place)} of ${mine.team.teams}.` : '';
    parts.push(el('p', { className: 'result-mine' }, `You finished ${resultLine(mine)}.${team} `, shareButton(mine, 'Share your result')));
  }
  $('results').replaceChildren(...parts);
  if (iWon) celebrate(state.world.id);
}

// A short shower of confetti for the winners, once per world on each device.
function celebrate(worldId) {
  const key = `unsaid:celebrated:${worldId}`;
  try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch { /* celebrate anyway */ }
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const colours = ['#ff7a29', '#ffd166', '#8fd18b', '#3cc2cf', '#a58bff', '#ff8a80'];
  const layer = el('div', { className: 'confetti', ariaHidden: 'true' });
  for (let i = 0; i < 80; i++) {
    const bit = el('span');
    bit.style.left = `${Math.random() * 100}%`;
    bit.style.background = colours[i % colours.length];
    bit.style.animationDelay = `${Math.random() * 0.8}s`;
    bit.style.animationDuration = `${2 + Math.random() * 1.5}s`;
    bit.style.transform = `rotate(${Math.random() * 360}deg)`;
    layer.append(bit);
  }
  document.body.append(layer);
  setTimeout(() => layer.remove(), 4500);
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
      started ? '. Every point you score counts for your team.' : '. Every point you score will count for your team.'));
    if (state.movedFrom) parts.push(el('p', { className: 'small' }, `The organiser moved you here from ${state.movedFrom}.`));
    if (state.otherTeamLink) parts.push(el('p', { className: 'small' }, `This link is for ${state.otherTeamLink}, but you're on ${state.myTeam.name}: players can't change team. Ask the organiser if you should.`));
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
  if (!started && state.isOwner && state.teamLinks) {
    // The organiser's next step: each team's link, right here rather than in the settings.
    parts.push(el('p', {}, el('b', { textContent: 'Next: send each team its link.' }),
      ' Whoever opens a team\'s link joins that team. You can move players between teams in Competition settings until the start.'));
  }
  if (!started) {
    const links = !state.isOwner ? null : state.teamLinks;
    parts.push(el('ul', { className: 'teams-forming' }, ...state.teams.slice().sort((a, b) => a.position - b.position).map((t) => {
      const copy = [];
      if (links && links[t.id]) {
        const button = el('button', { type: 'button', className: 'secondary small', textContent: 'Copy link' });
        button.addEventListener('click', () => copyText(compUrl(links[t.id]), `Link for ${t.name} copied. Send it to the ${t.name} players.`));
        copy.push(button, ' ');
      }
      return el('li', {}, ...copy, ...teamDot(t.position), el('b', { textContent: t.name }), ' ',
        el('span', { className: 'muted small', textContent: t.players.length ? t.players.join(', ') : 'nobody yet' }));
    })));
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
  loadPackChoices('cp');
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
      ...packChoice('cp'),
    } });
    location.href = `/w/${encodeURIComponent(made.id)}#created`;
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
    copy.addEventListener('click', () => copyText(compUrl(t.invite), `Link for ${t.name} copied.`));
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

// --- Word packs ---

// Fills a create dialog's Words choice: everyday words, curated packs, and the organiser's own.
async function loadPackChoices(prefix) {
  const select = $(`${prefix}-pack`);
  try {
    const { curated, custom } = await rootApi('/packs');
    const option = (p) => el('option', { value: p.id, textContent: `${p.name} (${p.words.toLocaleString()} words)`, title: p.description || '' });
    select.replaceChildren(el('option', { value: '', textContent: 'Everyday words' }),
      ...(curated.length ? [el('optgroup', { label: 'Word packs' }, ...curated.map(option))] : []),
      ...(custom.length ? [el('optgroup', { label: 'Your packs' }, ...custom.map(option))] : []));
  } catch { /* Everyday words only */ }
  $(`${prefix}-pack-mode`).hidden = !select.value;
}
for (const prefix of ['nw', 'cp']) {
  $(`${prefix}-pack`).addEventListener('change', () => { $(`${prefix}-pack-mode`).hidden = !$(`${prefix}-pack`).value; });
}
const packChoice = (prefix) => ($(`${prefix}-pack`).value
  ? { pack: $(`${prefix}-pack`).value, packMode: document.querySelector(`input[name="${prefix}-mode"]:checked`).value }
  : {});

// The organiser's own packs: a list, and an editor.
let myPacks = [];
let editingPack = null;

async function showPacks() {
  $('pack-form').hidden = true;
  $('packs-list').hidden = false;
  $('packs-buttons').hidden = false;
  $('packs-list').replaceChildren(el('li', { className: 'muted', textContent: 'Loading…' }));
  if (!$('packs-dialog').open) $('packs-dialog').showModal();
  try {
    ({ packs: myPacks } = await rootApi('/me/packs'));
    $('packs-list').replaceChildren(...(myPacks.length ? myPacks.map((p) => {
      const edit = el('button', { type: 'button', className: 'link row-actions', textContent: 'Edit' });
      edit.addEventListener('click', () => editPack(p));
      return el('li', {}, edit, el('b', { textContent: p.name }), ' ',
        el('span', { className: 'when', textContent: `${p.words.toLocaleString()} words` }),
        ...(p.reported.length ? [el('div', { className: 'small', textContent: `Players suggested ${p.reported.length} ${p.reported.length === 1 ? 'word' : 'words'}` })] : []),
        ...(p.description ? [el('div', { className: 'muted small', textContent: p.description })] : []));
    }) : [el('li', { className: 'muted', textContent: 'No packs yet. Make one with New pack.' })]));
  } catch (err) {
    $('packs-list').replaceChildren(el('li', { className: 'bad', textContent: err.message }));
  }
}

function editPack(pack) {
  editingPack = pack;
  $('pack-name').value = pack?.name ?? '';
  $('pack-description').value = pack?.description ?? '';
  $('pack-text').value = pack?.text ?? '';
  $('pack-delete').hidden = !pack;
  const reported = pack?.reported ?? [];
  const seen = el('button', { type: 'button', className: 'link', textContent: 'Mark as seen' });
  seen.addEventListener('click', async () => {
    try { await rootApi(`/packs/${encodeURIComponent(pack.id)}/seen`, { method: 'POST' }); $('pack-reported').replaceChildren(); } catch (err) { alert(err.message); }
  });
  $('pack-reported').replaceChildren(...(reported.length ? [
    el('p', {}, el('b', { textContent: 'Players suggested: ' }), reported.map((r) => `${r.word}${r.players > 1 ? ` (${r.players})` : ''}`).join(', '), '. ',
      'Add any that should count to a list above. ', seen),
  ] : []));
  $('packs-list').hidden = true;
  $('packs-buttons').hidden = true;
  $('pack-form').hidden = false;
}

$('menu-packs').addEventListener('click', showPacks);
$('packs-close').addEventListener('click', () => $('packs-dialog').close());
$('pack-new').addEventListener('click', () => editPack(null));
$('pack-cancel').addEventListener('click', showPacks);
$('pack-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const body = { name: $('pack-name').value, description: $('pack-description').value, text: $('pack-text').value };
  try {
    const saved = await rootApi(editingPack ? `/packs/${encodeURIComponent(editingPack.id)}` : '/packs', { method: 'POST', body });
    feedback(`Saved “${body.name}”: ${saved.words.toLocaleString()} words in ${saved.lists.length} ${saved.lists.length === 1 ? 'list' : 'lists'}.`, 'good');
    showPacks();
  } catch (err) {
    alert(err.message);
  }
});
$('pack-delete').addEventListener('click', async () => {
  if (!confirm(`Delete “${editingPack.name}”? This can't be undone.`)) return;
  try {
    await rootApi(`/packs/${encodeURIComponent(editingPack.id)}/delete`, { method: 'POST' });
    showPacks();
  } catch (err) {
    alert(err.message);
  }
});

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
// The (i) by the Big World's title shows or hides what it is.
$('round-info-button').addEventListener('click', () => {
  const open = $('round-about').hidden;
  $('round-about').hidden = !open;
  $('round-info-button').setAttribute('aria-expanded', String(open));
});

// Anything but the big world gets a banner saying where you are, and its own colour.
function renderBanner(state) {
  const kind = state.world.kind;
  document.body.dataset.kind = kind;
  $('world-banner').hidden = kind === 'public';
  if (kind === 'public') return;
  const pack = state.pack;
  const words = !pack ? 'Everyday words' : pack.mode === 'only' ? `${pack.name} words only` : `Everyday and ${pack.name} words`;
  const spelling = { uk: 'UK spelling', us: 'US spelling' }[state.world.spelling];
  $('banner-name').textContent = state.world.name;
  $('banner-about').textContent = [{ private: 'Private world', room: 'Room', competition: 'Competition' }[kind] ?? 'World', words, spelling].filter(Boolean).join(' · ');
}

function render(state) {
  document.title = `${state.world.name} · Unsaid`;
  renderBanner(state);
  const spellingNote = { uk: ' · UK spelling', us: ' · US spelling' }[state.world.spelling] ?? '';
  const packNote = state.pack ? ` · ${state.pack.name} words` : '';
  $('world-name').textContent = state.world.name + packNote + spellingNote;
  $('dict-size').textContent = state.dictionary.size.toLocaleString();
  $('dict-burned').textContent = state.dictionary.burned.toLocaleString();
  $('world-mult').textContent = state.multipliers.world.toFixed(1);
  $('prompt-mult').textContent = state.multipliers.prompt.toFixed(2).replace(/0$/, '');
  // The player's own place, without scrolling down to the leaderboard.
  const me = state.myStanding;
  $('my-standing').hidden = !me || state.world.ended; // A finished world's results card says it.
  if (me) $('my-standing').replaceChildren('You: ', el('b', { textContent: `${ordinal(me.place)} of ${me.of}` }), ` · ${me.score} ${Math.abs(me.score) === 1 ? 'point' : 'points'}`);

  // A competition before its start has no prompt yet: just say so, without empty counters.
  const waiting = state.world.kind === 'competition' && !state.world.ended && state.now < state.world.startsAt;
  document.querySelector('.prompt-card').classList.toggle('waiting', waiting);
  // A finished world shows its result in place of the prompt.
  document.querySelector('.prompt-card').classList.toggle('results', state.world.ended);
  $('results').hidden = !state.world.ended;
  if (state.world.ended) renderResults(state);
  if (state.prompt) {
    $('prompt-label').textContent = state.prompt.label;
    $('remaining').textContent = state.prompt.remaining;
    // The meter fills towards the prompt's end, which comes before every answer is said.
    const toSay = Math.max(1, state.prompt.availableAtStart - state.prompt.endsWhenLeft);
    const used = Math.min(1, (state.prompt.availableAtStart - state.prompt.remaining) / toSay);
    $('meter-fill').style.width = `${Math.round(used * 100)}%`;
    renderClock(state);
  } else {
    $('prompt-label').textContent = state.world.ended ? 'Finished' : waiting ? 'The first prompt appears at the start' : 'This world has run out of words';
    $('remaining').textContent = '0';
    $('ends-text').replaceChildren('Ends in ', el('b', { id: 'ends-in', textContent: '–' }));
  }
  const competition = state.world.kind === 'competition';
  $('copy-link').hidden = !state.invite;
  $('copy-link').textContent = 'Copy invite link';
  $('world-settings').hidden = !state.isOwner;
  $('world-settings').textContent = competition ? 'Competition settings' : 'World settings';
  renderCompetition(state);
  // On the big world, a nudge towards private worlds for anyone who can't make one yet.
  $('create-own').hidden = state.world.kind !== 'public' || ['host', 'organiser'].includes(state.account?.plan);
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
  renderLastUnsaid(state.recentPrompt);

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

// closingWord: the answer that finished the prompt (it earned the closing bonus).
function saidList(words, emptyText, closingWord = null) {
  return el('ol', { className: 'recent' }, ...(words.length ? words.map((r) => el('li', {},
    saidWord(r),
    ...(closingWord && r.word === closingWord ? [el('span', { className: 'last-answer', title: 'Finished the prompt: 10 bonus points', textContent: '🏁' })] : []),
    el('span', { className: 'muted' }, ' by ', ...teamDot(r.team), `${r.by}${r.familyCount ? ` (+${r.familyCount} family)` : ''}`),
    el('span', { className: 'points', textContent: `+${r.points}` }),
  )) : [el('li', { className: 'muted', textContent: emptyText })]));
}

const countText = (n) => `${n} said`;

// What nobody said in an ended prompt: the commonest answers left, the "oh, of course!" words.
function unsaidText(u) {
  if (!u.count) return 'Every answer was said!';
  const more = u.count - u.words.length;
  return `${u.words.join(', ')}${more > 0 ? `${u.words.length ? ' and ' : ''}${more.toLocaleString()} more` : ''}`;
}

// The latest earlier prompt that someone played (from the server): one nobody played would make
// the page look dead.
function renderLastUnsaid(last) {
  $('last-unsaid').hidden = !last;
  if (!last) return;
  $('last-unsaid').replaceChildren(
    el('p', { className: 'eyebrow', textContent: last.lastRound ? 'Recent prompt · last round' : 'Recent prompt' }),
    el('p', {}, el('b', { textContent: last.prompt }), el('span', { className: 'muted', textContent: ` · ${countText(last.words.length)}` })),
    el('p', {}, last.unsaid.count ? el('span', { className: 'muted', textContent: 'Nobody said: ' }) : '', unsaidText(last.unsaid),
    ),
    ...(last.closingWord ? [el('p', { className: 'small' }, `🏁 ${last.words.find((w) => w.word === last.closingWord)?.by ?? 'Someone'} finished it with “${last.closingWord}”, for 10 bonus points.`)] : []),
  );
}

// The current prompt's words in full, with earlier prompts collapsed underneath.
// Just said shows this many of the current prompt's words until "Show all" is pressed, so the
// leaderboard isn't pushed far down the page on a phone.
const SAID_SHOWN = 10;
let showAllSaid = false;

function renderSaid(groups, currentPromptId) {
  const parts = [];
  for (const group of groups) {
    if (group.promptId === currentPromptId) {
      parts.push(el('p', { className: 'group-title', textContent: `This prompt · ${countText(group.words.length)}` }));
      const hidden = showAllSaid ? 0 : group.words.length - SAID_SHOWN;
      parts.push(saidList(hidden > 0 ? group.words.slice(0, SAID_SHOWN) : group.words, 'Nothing said yet. Be the first.'));
      if (hidden > 0 || (showAllSaid && group.words.length > SAID_SHOWN)) {
        const more = el('button', { type: 'button', className: 'link small', textContent: showAllSaid ? 'Show fewer' : `Show all ${group.words.length}` });
        more.addEventListener('click', () => { showAllSaid = !showAllSaid; renderSaid(groups, currentPromptId); });
        parts.push(more);
      }
    } else if (!group.words.length) {
      continue;
    } else {
      const details = el('details', { className: 'earlier', open: openPrompts.has(group.promptId) },
        el('summary', {}, el('span', { textContent: group.prompt }), el('span', { className: 'muted', textContent: ` · ${countText(group.words.length)}` })),
        ...(group.unsaid ? [el('p', { className: 'small unsaid-line' }, group.unsaid.count ? el('span', { className: 'muted', textContent: 'Nobody said: ' }) : '', unsaidText(group.unsaid))] : []),
        saidList(group.words, 'Nothing was said.', group.closingWord));
      details.addEventListener('toggle', () => (details.open ? openPrompts.add(group.promptId) : openPrompts.delete(group.promptId)));
      parts.push(details);
    }
  }
  // Prompts nobody played aren't listed: they'd only make the page look quiet.
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
    // A report is about the prompt the miss was for, earlier ones included.
    const status = m.reportStatus ?? (reported.has(m.word) ? 'reported' : null);
    if (status) {
      rows.at(-1).append(el('span', { className: 'muted small-note', textContent: reportStatusText(status, m, currentPromptId) }));
    } else if (isReportable(m)) {
      rows.at(-1).append(reportButton(m.word, m.reason, m.promptId));
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

// Messages fade away on their own: good news after a few seconds, problems after longer, and
// longest when there's a button to press (see keepFeedback). Not while the pointer or keyboard
// focus is on the message, so it can still be read or used.
const FEEDBACK_MS = { good: 6000, bad: 10000 };
let feedbackTimer = null;

function feedback(text, kind) {
  const box = $('feedback');
  box.textContent = text;
  box.className = `feedback ${kind}`;
  if (text) keepFeedback(FEEDBACK_MS[kind] ?? FEEDBACK_MS.good);
  else clearTimeout(feedbackTimer);
}

function keepFeedback(ms) {
  clearTimeout(feedbackTimer);
  feedbackTimer = setTimeout(function fade() {
    const box = $('feedback');
    if (box.matches(':hover') || box.contains(document.activeElement)) { feedbackTimer = setTimeout(fade, 2000); return; }
    box.classList.add('fading');
    feedbackTimer = setTimeout(() => { box.textContent = ''; box.className = 'feedback'; }, 500);
  }, ms);
}

// One way of writing when something happened: "at 14:05" today, "on 13 Oct, 14:05" before.
function saidAt(ms) {
  const d = new Date(ms);
  const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
  return d.toDateString() === new Date().toDateString() ? `at ${time}` : `on ${d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}, ${time}`;
}
const shortDate = (ms) => new Date(ms).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const penaltyText = (r) => (r.penalty ? ` −${r.penalty} ${r.penalty === 1 ? 'point' : 'points'}.` : '');

// A pack-only world knows only its pack's words: say so, or everyday words look like a fault.
const notAWord = (w) => (lastState?.pack?.mode === 'only'
  ? `“${w}” isn't in this world's ${lastState.pack.name} words. No penalty.`
  : `We don't know “${w}”. No penalty.`);

const REASONS = {
  'not-a-word': (w) => notAWord(w),
  'doesnt-fit': (w, r) => `“${w}” doesn't fit${r.why ? `: ${r.why}` : ' the prompt'}.${r.penalty ? penaltyText(r) : ' No penalty.'}`,
  'not-in-category': (w, r) => `“${w}” isn't on our list for “${r.category}”. No penalty.`,
  'wrong-spelling': (w, r) => `This world uses ${r.spelling === 'uk' ? 'UK' : 'US'} spelling${r.suggestions?.length ? `: try “${r.suggestions[0]}”` : ''}. No penalty.`,
  'already-burned': (w, r) => r.burned
    ? (r.byYou
      ? `You already said “${r.burned.playedWord}”${r.burned.playedWord !== w ? ` (which uses up “${w}”)` : ''} ${saidAt(r.burned.at)}, for “${r.burned.prompt}”. No penalty.`
      : `${r.otherSpelling ? `“${w}” is the same answer as “${r.burned.playedWord}”, which ${r.burned.by} said`
        : r.burned.playedWord !== w ? `${r.burned.by} used up “${w}” by saying “${r.burned.playedWord}”`
        : `${r.burned.by} already said “${w}”`} ${saidAt(r.burned.at)}, for “${r.burned.prompt}”.${r.penalty ? penaltyText(r)
        : r.teamMate ? ' No penalty, as it was your team-mate.' : r.justNow ? ' No penalty, as it was moments ago.' : ' No penalty, as it was hidden.'}`)
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
      // The prompt on screen: if it changed a moment ago, the word still counts for it.
      result = await api(`/${encodeURIComponent(worldId)}/words`, { method: 'POST', body: { word, promptId: lastState?.prompt?.id } });
    } catch (err) {
      if (err.status === 401) { setSession(null); refresh(); } // Signed out elsewhere: show the sign-in box.
      if (err.status === 410) { refresh(); throw new Error(`${err.message} The result is at the top.`); } // It just finished.
      throw err;
    }
    if (result.ok) {
      const family = result.alsoBurned.length ? ` Gone with it: ${result.alsoBurned.join(', ')}.` : '';
      const streak = result.streak > 1 ? ` That's ${result.streak} days in a row!` : '';
      const closing = result.closing ? ' That finished the prompt: 10 bonus points!' : '';
      const loose = result.uncounted ? " It's a looser answer, so it doesn't bring the count down or get the prompt bonus." : '';
      const forOld = result.previousPrompt ? ` It counted for the last prompt, “${result.previousPrompt}”.` : '';
      feedback(`You said “${result.word}” for ${result.points} ${result.points === 1 ? 'point' : 'points'}.${forOld}${closing}${loose} Nobody can say it again.${family}${streak}`, 'good');
      input.value = '';
    } else {
      const forOld = result.previousPrompt ? ` (Judged against the last prompt, “${result.previousPrompt}”, as it had only just changed.)` : '';
      feedback(REASONS[result.reason](result.word, result) + forOld, 'bad');
      if (isReportable(result)) {
        $('feedback').append(' ', reportButton(result.word, result.reason, result.promptId));
        keepFeedback(20000); // Time to decide whether to report it.
      }
      input.select();
    }
    refresh();
  } catch (err) {
    feedback(err.message, 'bad');
  }
});

// Players can tell us about real answers missing from a meaning list, or real words missing
// from the dictionary. The admin page (/admin) reviews the reports.
const isReportable = (r) => r.reason === 'not-in-category' || (r.reason === 'not-a-word' && /^[a-z]{3,30}$/.test(r.word.replace(/[\s'’-]/g, '')));

// promptId: the prompt the miss was for, so the report is about the right meaning.
function reportButton(word, reason, promptId) {
  const text = reason === 'not-a-word' ? "Report it: it's a real word" : 'Report it: it should count';
  const button = el('button', { type: 'button', className: 'link', textContent: text });
  button.addEventListener('click', async () => {
    try {
      await api(`/${encodeURIComponent(worldId)}/reports`, { method: 'POST', body: { word, promptId } });
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

// The header's account area: "Sign in", or the player's name opening a menu.
function renderAccount(account, adminUrl) {
  $('signin-link').hidden = Boolean(account);
  $('account').hidden = !account;
  $('admin-link').hidden = Boolean(account) || !adminUrl;
  if (adminUrl) { $('admin-link').href = adminUrl; $('menu-admin').href = adminUrl; }
  $('menu-admin').hidden = !adminUrl;
  if (!account) { closeMenu(); return; }
  $('account-button').textContent = account.name;
  $('new-competition').hidden = account.plan !== 'organiser';
  $('menu-packs').hidden = account.plan !== 'organiser';
  $('menu-reminders').textContent = `Daily email: ${account.reminders ? 'on' : 'off'}`;
}

function closeMenu() {
  $('account-menu').hidden = true;
  $('account-button').setAttribute('aria-expanded', 'false');
}
$('account-button').addEventListener('click', (e) => {
  e.stopPropagation();
  const open = $('account-menu').hidden;
  $('account-menu').hidden = !open;
  $('account-button').setAttribute('aria-expanded', String(open));
  if (open) $('account-menu').querySelector('button:not([hidden]), a:not([hidden])').focus();
});
// Choosing anything, clicking elsewhere or pressing Escape closes the menu.
$('account-menu').addEventListener('click', closeMenu);
document.addEventListener('click', (e) => { if (!$('account').contains(e.target)) closeMenu(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('account-menu').hidden) { closeMenu(); $('account-button').focus(); } });
$('signin-link').addEventListener('click', () => { showSigninStep('email'); $('signin').hidden = false; $('signin').scrollIntoView({ block: 'center' }); });
$('menu-worlds').addEventListener('click', () => showWorlds());
$('menu-games').addEventListener('click', () => showHistory());
$('menu-reminders').addEventListener('click', async () => {
  const on = !lastState?.account?.reminders;
  try {
    await rootApi('/me/reminders', { method: 'POST', body: { on } });
    feedback(on
      ? "Daily email on: each morning, when the big world starts afresh, we'll email you unless you've already played. Turn it off here, or from the link in any email."
      : 'Daily email off.', 'good');
    refresh();
  } catch (err) { feedback(err.message, 'bad'); }
});
$('menu-signout').addEventListener('click', async () => {
  try { await authApi('/logout', { method: 'POST' }); } catch { /* signed out locally anyway */ }
  setSession(null);
  feedback('Signed out.', 'good');
  refresh();
});

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
      ...(w.packName ? [el('span', { className: 'when', textContent: ` · ${w.packName} words` })] : []),
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
    const best = (value, label) => el('div', {}, el('b', { textContent: value }), el('span', { textContent: label }));
    const streaks = [
      best(bests.streak, `${bests.streak === 1 ? 'day' : 'days'} in a row${bests.streak && !bests.playedToday ? ': play today to keep it' : ''}`),
      best(bests.bestStreak, 'best run of days'),
    ];
    if (!games.length) {
      $('history').replaceChildren(el('div', { className: 'bests' }, ...streaks), el('p', { className: 'muted', textContent:
        `No finished games yet. Big world rounds finish at the end of each round, and rooms when their words run out.${bests.wordsSaid ? ` So far you've said ${bests.wordsSaid.toLocaleString()} words.` : ''}` }));
      return;
    }
    $('history').replaceChildren(
      el('div', { className: 'bests' },
        ...streaks,
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
        shareButton(g),
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
    $('lookup-result').textContent = !r.inDictionary ? (lastState?.pack?.mode === 'only' ? `“${r.word}” isn't in this world's ${lastState.pack.name} words.` : `We don't know “${r.word}”.`)
      : r.burned ? `${r.burned.by} said “${r.word}” ${saidAt(r.burned.at)}${r.burned.playedWord !== r.word ? ` (with “${r.burned.playedWord}”)` : ''}, for “${r.burned.prompt}”.`
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

$('create-own').addEventListener('click', () => $('new-world').click());
$('new-world').addEventListener('click', () => {
  if (session && lastState?.account && lastState.account.plan === 'free') {
    feedback('Private worlds, for you and the friends you invite, come with the Host plan. Meanwhile you can join anyone\'s private world with their invite link.', '');
    return;
  }
  if (!session) {
    feedback('Sign in first: private worlds are for you and the friends you invite.', '');
    showSigninStep('email');
    $('signin').hidden = false;
    $('signin').scrollIntoView({ block: 'center' });
    return;
  }
  loadPackChoices('nw');
  $('new-world-dialog').showModal();
});
$('new-world-cancel').addEventListener('click', () => $('new-world-dialog').close());
$('new-world-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const spelling = new FormData(e.target).get('spelling');
  try {
    const promptHours = Number(new FormData(e.target).get('pace'));
    const maxPlayers = $('new-world-max').value ? Number($('new-world-max').value) : null;
    const world = await api('', { method: 'POST', body: { name: $('new-world-name').value, spelling, promptHours, maxPlayers, ...packChoice('nw') } });
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

// The link at the bottom of a daily email stops them, without signing in.
const stopEmails = new URLSearchParams(location.search).get('stop-emails');
if (stopEmails) {
  history.replaceState(null, '', location.pathname);
  rootApi('/reminders/stop', { method: 'POST', body: { token: stopEmails } })
    .then(() => feedback("Done: we won't send you the daily email any more.", 'good'))
    .catch((err) => feedback(err.message, 'bad'));
}

// Just made a competition: say what to do next.
if (location.hash === '#created') {
  history.replaceState(null, '', location.pathname + location.search);
  feedback('Your competition is ready. Now send each team its link, using the Copy link buttons below.', 'good');
}

refresh();
schedulePoll();
