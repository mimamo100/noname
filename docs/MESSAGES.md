# The game's messages

Every message players (and organisers) see, grouped by where it appears, for reviewing the
wording. Mark up what you'd like changed and it'll be edited in the code.

- **{braces}** stand for something filled in: a word, a name, a number. *{word}* is what the
  player typed; *{name}* a player's name; *{prompt}* a prompt such as "A bird with no E".
- **Where** says which file holds the wording, and the part of it: `app.js` is
  `public/app.js` (the page); the `.php` files are in `src/` (the server), except `api.php`,
  which is in `public/`.
- Fixed text on the page itself (How to play, the dialogs' headings and labels, buttons such as
  "Say it") is in `public/index.html` and isn't repeated here.
- Listed 14 October 2026. Messages added later won't be here until the list is redone.

## 1. Playing a word

Shown under the word box; they fade after a few seconds (see section 13).

### Accepted

| Message | Where |
|---|---|
| You said “{word}” for {n} points. Nobody can say it again. | app.js · play-form |
| …added when they apply: | |
| ` Gone with it: {other forms}.` | app.js · play-form |
| ` That's {n} days in a row!` | app.js · play-form |
| ` That finished the prompt: 10 bonus points!` | app.js · play-form |
| ` It's a looser answer, so it doesn't bring the count down or get the prompt bonus.` | app.js · play-form |
| ` It counted for the last prompt, “{prompt}”.` | app.js · play-form |

### Not accepted

| Message | Where |
|---|---|
| We don't know “{word}”. No penalty. | app.js · notAWord |
| “{word}” isn't in this world's {pack} words. No penalty. *(a pack-only world)* | app.js · notAWord |
| “{word}” doesn't fit: {reason}. −2 points. *(reasons below)* | app.js · REASONS |
| “{word}” isn't on our list for “{category}”. No penalty. | app.js · REASONS |
| This world uses UK spelling: try “{other spelling}”. No penalty. *(or US)* | app.js · REASONS |
| You already said “{word}” {when}, for “{prompt}”. No penalty. | app.js · REASONS |
| You already said “{played}” (which uses up “{word}”) {when}, for “{prompt}”. No penalty. | app.js · REASONS |
| {name} already said “{word}” {when}, for “{prompt}”. −2 points. | app.js · REASONS |
| {name} used up “{word}” by saying “{played}” {when}, for “{prompt}”. −2 points. | app.js · REASONS |
| “{word}” is the same answer as “{played}”, which {name} said {when}, for “{prompt}”. −2 points. | app.js · REASONS |
| …instead of −2 points when it's free: ` No penalty, as it was your team-mate.` / ` No penalty, as it was moments ago.` / ` No penalty, as it was hidden.` | app.js · REASONS |
| ` (Judged against the last prompt, “{prompt}”, as it had only just changed.)` *(added)* | app.js · play-form |
| {when}: “at 14:05” today, “on 13 Oct, 14:05” before | app.js · saidAt |
| Slow down! You can make 20 guesses a minute. | Game.php · checkRateLimit |
| {message} The result is at the top. *(a word sent just after the world finished)* | app.js · play-form |

### Why a word doesn't fit (the {reason} above)

| Reason | Where |
|---|---|
| it doesn't start with {letters} | Prompts.php · whyNot |
| it doesn't end in {letters} | Prompts.php · whyNot |
| it has no {letters} | Prompts.php · whyNot |
| it has an {letter} / it has a {letter} | Prompts.php · whyNot |
| it doesn't start and end with {letter} | Prompts.php · whyNot |
| it has no double {letter} | Prompts.php · whyNot |
| it has {n} {letter}s, not {m} | Prompts.php · whyNot |
| it's {n} letters, not {m} or more *(also “or fewer”, or exactly “not {m}”)* | Prompts.php · whyNot |
| …for a plural: it's {n} letters (“{plural}” counts as “{singular}”), not {m} or more | Prompts.php · whyNot |
| its letters aren't in alphabetical order: {X} comes before {Y} | Prompts.php · alphaWhy |
| it has more than one vowel | Prompts.php · whyNot |
| it has vowels other than {vowel} / it has no {vowel} | Prompts.php · whyNot |

### Reporting a word

| Message | Where |
|---|---|
| Report it: it's a real word *(link)* | app.js · reportButton |
| Report it: it should count *(link)* | app.js · reportButton |
| Thanks, reported. My misses will show what happens to it. | app.js · reportButton |
| Only words of 3 to 30 letters can be reported | Game.php · report |
| That word is already in the dictionary | Game.php · report |

## 2. My words and My misses

| Message | Where |
|---|---|
| My words *(tab)* | index.html |
| Only you can see these. {n} words (the latest), {n} points before penalties. | app.js · renderWords |
| No words yet. | app.js · renderWords |


| Message | Where |
|---|---|
| Only you can see these. | index.html |
| No misses yet. Nice. | app.js · renderMisses |
| Not in the dictionary / Didn't fit / Already said / Not on our list / Other spelling *(the reason beside each miss)* | app.js · MISS_REASONS |
| free / −2 *(beside each miss)* | app.js · renderMisses |
| Reported: waiting for a decision | app.js · REPORT_STATUS |
| Report reviewed: it doesn't count | app.js · REPORT_STATUS |
| Report accepted: it counts now, so try it again | app.js · reportStatusText |
| Report accepted: it counts from now on | app.js · reportStatusText |
| Report accepted: you've said it since | app.js · reportStatusText |
| Report accepted, but it's used up: {name} said it for “{prompt}” | app.js · reportStatusText |

## 3. The prompt card and the feed

| Message | Where |
|---|---|
| Current prompt | index.html |
| At least {n} words left unsaid | index.html |
| World ×{n} · Prompt ×{n} | index.html |
| Ends after {n} more or in {time} | app.js · renderClock |
| The first prompt appears at the start *(a competition before it starts)* | app.js · render |
| This world has run out of words | app.js · render |
| Recent prompt *(box: the latest earlier prompt someone played; “Recent prompt · last round” early in a new round)* · {prompt} · {n} said | app.js · renderLastUnsaid |
| Nobody said: {words} and {n} more | app.js · unsaidText |
| Every answer was said! | app.js · unsaidText |
| 🏁 {name} finished it with “{word}”, for 10 bonus points. | app.js · renderLastUnsaid |
| This prompt · {n} said | app.js · renderSaid |
| Nothing said yet. Be the first. | app.js · renderSaid |
| Show all {n} / Show fewer | app.js · renderSaid |
| Earlier prompts *(three that had words; early in a new round, made up from the last round's)* · {prompt} · {n} said · last round | app.js · renderSaid |
| Earlier prompts · the last {n} of {n} *(when there were more)* | app.js · renderSaid |
| Nothing was said. | app.js · renderSaid |
| {name} (+{n} family) *(in the feed)* | app.js · saidList |
| Tap to show the word *(a masked word, p\*\*\*s)* | app.js · saidWord |
| Finished the prompt: 10 bonus points *(the 🏁, on hover)* | app.js · saidList |

## 4. Leaderboard and Who said it?

| Message | Where |
|---|---|
| Scores include 2 points off for each wrong guess, so they can go down. | index.html |
| Nobody has tried this prompt yet. First in leads. | index.html |
| Playing now *(the green dot by a name, on hover: said or tried a word in the last 5 minutes)* | app.js · boardRows |
| {name} said “{word}” {when}, for “{prompt}”. | app.js · lookup-form |
| {name} said “{word}” {when} (with “{played}”), for “{prompt}”. | app.js · lookup-form |
| “{word}” is still unsaid. | app.js · lookup-form |
| We don't know “{word}”. / “{word}” isn't in this world's {pack} words. | app.js · lookup-form |

## 5. When a world finishes (the results card)

| Message | Where |
|---|---|
| Final result | app.js · renderResults |
| {team} win! / It's a draw! | app.js · renderResults |
| {team} {score} · {team} {score} | app.js · renderResults |
| Well played {names}. | app.js · renderResults |
| {name} wins! / {name} and {name} share first place! | app.js · renderResults |
| with {n} points, out of {n} players | app.js · renderResults |
| Top scorer: {name}, {n} points. | app.js · renderResults |
| You finished {medal} {place} of {n} with {n} points. {team} came {place} of {n}. | app.js · renderResults, resultLine |
| Share your result *(button)* | app.js · renderResults |
| This round has finished. Reload the page for the new one. *(playing)* | Game.php · endedMessage |
| This round of the big world has finished. *(looking)* | Game.php · endedMessage |
| This room has run out of words. Join a new room from the home page. | Game.php · endedMessage |
| This competition has finished. | Game.php · endedMessage |
| This world has run out of words | Game.php · endedMessage |
| Join a new room / Go to the big world *(button)* | app.js · renderLobby |

## 6. Sharing a result

| Message | Where |
|---|---|
| Unsaid: my result in {world} *(the title; an email's subject)* | app.js · shareTitle |
| Unsaid · {world} | app.js · shareText |
| {medal} {place} of {n} · {n} points · {n} words | app.js · shareText |
| Team {team}: {place} of {n} | app.js · shareText |
| Best word: {word} (+{n}) | app.js · shareText |
| Unsaid is a word game where every word can only be said once. Have a go: | app.js · shareText |
| Copied: paste it anywhere *(the button, after copying)* | app.js · shareResult |
| Copy your result: *(when copying isn't possible)* | app.js · shareResult |

## 7. The big world and rooms

| Message | Where |
|---|---|
| The Big World, {date} *(the round's name; the banner shows it as “The Big World · {date}”)* | Lobby.php · currentRound, app.js · renderBanner |
| The Big World *(the banner's title)* | app.js · renderBanner |
| Everyone signed in plays here, and it starts afresh every day. *(or every {n} hours; shown with the (i) by the title)* | app.js · renderBanner |
| This round ends in {time} *(in the banner)* | index.html, app.js · renderClock |
| Last round *(the box's title)*, then {medal} {name} · {n} points for the top three | app.js · renderLobby |
| You came {place} of {n} with {n} points. Share your result | app.js · renderLobby |
| Play in a room of up to 60 players, its own world for weeks *(its own box)* | index.html |
| Join a room / Back to {room} *(button)* | app.js · renderLobby |
| Finding you a room… *(the button while joining)* | app.js · joinRoom |
| Create your own world *(link by the date)* | index.html |
| Room {n} *(a room's name)* | Lobby.php · newRoom |
| You can watch this room, but only its own players can say words. Join a room to play. | app.js · renderLobby |
| Sign in first to join a room. | app.js · joinRoom |
| The rooms are busy. Please try again. | Lobby.php · joinRoom |
| All the rooms are full right now. Try the big world, or come back later. | Lobby.php · newRoom |
| This room is for its own players. Join a room from the home page. | Lobby.php · playerFor |
| Your seat in this room went to someone new while you were away. Join a new room from the home page. | Lobby.php · playerFor |

## 8. Private worlds

### The page

| Message | Where |
|---|---|
| {world} · Private world · Everyday words · UK spelling *(the banner; also “{pack} words only”, “Everyday and {pack} words”, Room, Competition)* | app.js · renderBanner |
| Back to the big world | index.html |
| Copy invite link / World settings *(header links)* | app.js · render |
| Invite link copied. Send it to your friends! | app.js · copy-link |
| Private worlds, for you and the friends you invite, come with the Host plan. Meanwhile you can join anyone's private world with their invite link. | app.js · new-world |
| Sign in first: private worlds are for you and the friends you invite. | app.js · new-world |

### World settings (the owner)

| Message | Where |
|---|---|
| Settings saved. | app.js · settings-form |
| Invite link copied. | app.js · settings-copy |
| Make a new invite link? The old one will stop working. | app.js · settings-new-invite |
| New invite link made. Send it to anyone still to join. | app.js · settings-new-invite |
| End this world for everyone? Final standings go into everyone's Games, under Your account. This can't be undone. | app.js · settings-end |
| The world has ended. | app.js · settings-end |
| Remove {name}? Their words stay used up, but they leave the leaderboard and can't come back. | app.js · loadSettings |
| {name} has been removed. | app.js · loadSettings |

### Refusals from the server

| Message | Where |
|---|---|
| Creating private worlds needs the Host plan. | PrivateWorlds.php · create |
| You have {n} open private worlds, the most at once. End one to start another. | PrivateWorlds.php · create |
| Choose the pack only, or the pack with everyday words | PrivateWorlds.php · packOptions |
| Prompts can last 1, 4 or 24 hours | PrivateWorlds.php · paceMs |
| The player limit must be between 2 and {n} | PrivateWorlds.php · maxPlayers |
| This is a private world. You need an invite link from one of its players. | PrivateWorlds.php · checkCanView |
| This invite link doesn't work any more: the world's owner may have made a new one. Ask one of its players for the latest link. | PrivateWorlds.php · checkCanView |
| This invite link has been replaced. Ask one of the players for the new one. | PrivateWorlds.php · playerFor |
| This world is closed to new players. | PrivateWorlds.php · playerFor |
| This world is full. | PrivateWorlds.php · playerFor |
| You've been removed from this world. | PrivateWorlds.php · checkCanView, playerFor |
| Only the owner can change this world. | PrivateWorlds.php · ownedWorld |
| No player of that name here. | PrivateWorlds.php · removePlayer |
| You can't remove yourself from your own world. | PrivateWorlds.php · removePlayer |
| This world has already ended. | PrivateWorlds.php · end |
| Other players have joined, so end the world before deleting it. | PrivateWorlds.php · delete |

## 9. Competitions

### Players and watchers

| Message | Where |
|---|---|
| Starts **{time}** · Ends **{time}** *(with the day, “Thu 15 Oct, 20:00”, when it isn't today)* | app.js · drawCompetitionClock |
| Starts in **{m:ss}** / LIVE **{m:ss}** left *(a countdown, ticking every second; “3 days” when far off)* | app.js · drawCompetitionClock |
| Every word stays said for the rest of this world, so a word from an earlier prompt can't be said again. Not sure? Try it in Who said it? first: it's free. *(under the word box, in pack-only worlds)* | index.html |
| The organiser has moved you to {team}. | app.js · render |
| You're in {team}. Every point you score will count for your team. *(before the start; “counts” after)* | app.js · renderCompetition |
| The organiser moved you here from {team}. | app.js · renderCompetition |
| This link is for {team}, but you're on {team}: players can't change team. Ask the organiser if you should. | app.js · renderCompetition |
| This is the link for {team}. Join {team} *(button)* | app.js · renderCompetition |
| You've joined {team}. | app.js · renderCompetition |
| You're watching. To play, open your team's invite link from the organiser. | app.js · renderCompetition |
| nobody yet *(a team with no players)* | app.js · renderCompetition |

### The organiser

| Message | Where |
|---|---|
| Your competition is ready. Now send each team its link, using the Copy link buttons below. | app.js · (after creating) |
| Next: send each team its link. Whoever opens a team's link joins that team. You can move players between teams in Competition settings until the start. | app.js · renderCompetition |
| Link for {team} copied. Send it to the {team} players. | app.js · renderCompetition |
| Link for {team} copied. | app.js · loadCompSettings |
| Watching link copied. | app.js · cs-copy-watch |
| Make a new link for {team}? The old one stops working. / New link for {team}. | app.js · loadCompSettings |
| Remove {team}? / {team} removed. | app.js · loadCompSettings |
| Team renamed. / Team added. / Settings saved. | app.js · loadCompSettings, cs-add-team, comp-settings-form |
| Move to… *(menu)* / {name} moved. | app.js · loadCompSettings |
| Remove {name}? Their words stay used up, but their points leave the team and they can't come back. / {name} removed. | app.js · loadCompSettings |
| End the competition now for everyone? Final standings are as they are now. This can't be undone. / The competition has ended. | app.js · cs-end |

### Refusals from the server

| Message | Where |
|---|---|
| Running competitions needs the Organiser plan. | Competitions.php · create |
| You have {n} competitions planned or running, the most at once. End one to start another. | Competitions.php · create |
| The start time has passed / The end must be after the start / The end must be in the future | Competitions.php · times, update |
| A competition can last up to 31 days | Competitions.php · times, update |
| Prompts can last 5, 10, 15 or 30 minutes, an hour, 4 hours or a day | Competitions.php · pace |
| About {n} prompts in {length}, or more if they're said quickly. *(under the prompt length; the length defaults to one giving 4 to 6 prompts)* | app.js · compPaceHint |
| {pack} has {n} answers: enough for about {n} prompts. This competition is set for about {n}, so the pack may run dry: make it shorter, or add everyday words. *(under Words, for a pack on its own)* | app.js · compPackHint |
| Teams can have up to {n} players | Competitions.php · teamSize |
| Every team needs a name / Two teams have the same name / There's already a team called {team} | Competitions.php · teamNames, insertTeam, renameTeam |
| A competition needs 2–20 teams *(when creating)* / A competition can have up to {n} teams / A competition needs at least {n} teams | Competitions.php · teamNames, addTeam, removeTeam |
| This is a private competition. You need your team's invite link. | Competitions.php · checkCanView |
| To play, open your team's invite link. Ask the organiser for it. | Competitions.php · playerFor |
| {team} is full. | Competitions.php · playerFor |
| The competition hasn't started yet. | Competitions.php · checkStarted; Game.php · noPromptError |
| This competition has finished. / This competition has already finished. | Competitions.php · playerFor, update, end |
| You've been removed from this competition. | Competitions.php · checkCanView, playerFor |
| Only the organiser can change this competition. | Competitions.php · ownedCompetition |
| Teams can only be added before the start. / Teams can only be removed before the start. | Competitions.php · addTeam, removeTeam |
| Move its players to other teams first. | Competitions.php · removeTeam |
| Players can only change team before the start. | Competitions.php · movePlayer |
| No such team here. / No player of that name here. | Competitions.php · ownedTeam, movePlayer, removePlayer |
| End the competition before deleting it. | Competitions.php · delete |

## 10. Word packs

| Message | Where |
|---|---|
| Everyday words / Word packs / Your packs *(the Words menu)* | app.js · loadPackChoices |
| {pack} ({n} words) | app.js · loadPackChoices |
| No packs yet. Make one with New pack: a few lists of words on a theme, and it's ready to use in a private world or competition. | app.js · renderPacks |
| New word pack / Edit “{pack}” *(the editor's title)* | app.js · editPack |
| Players suggested: {words}. Add any that should count to a list above. Mark as seen | app.js · editPack |
| Saved “{pack}”: {list}, {n} answers; {list}, {n} answers. (Not in our everyday dictionary, so check the spelling: {words}.) | app.js · pack-form |
| Start each list with its prompt (“A planet”), then one answer per line. Leave a blank line between lists. · Put other forms of the same answer on its line after commas: nucleus, nuclei or black hole, black holes. A phrase on its own is fine: egg cup. · Plurals count by themselves, but other forms don't: for a list of actions, add the forms players will type, like grill, grilling, grilled. · Aim for 20 or more answers in each list; a longer competition needs more. Words said stay said, so a small pack soon runs dry. · Keep the lists from overlapping, and include the everyday words players will try first (dish, pan). · Each answer needs 3–30 letters, a to z (spaces, hyphens and apostrophes don't count). *(the guide in the pack editor)* | index.html |
| *(Errors in a dialog show in red above its buttons, not in an alert box.)* | app.js · formError |
| Making your own word packs needs the Organiser plan. | Packs.php · create |
| Word packs come with the Host and Organiser plans. | Packs.php · checkUsable |
| Your own word packs come with the Organiser plan. | Packs.php · checkUsable |
| Give the pack a name | Packs.php · check |
| Add at least one list: a label line, then one word per line | Packs.php · check |
| Please fix: {problems} | Packs.php · check |
| A pack needs at least {n} words / A pack can have up to {n} words | Packs.php · check |
| “{word}” isn't a word of 3–30 letters a to z / “{word}” is on the blocklist *(problems)* | Packs.php · build |
| A world that is still running uses this pack. End it first. | Packs.php · delete |
| No such pack of yours | Packs.php · owned |

## 11. Signing in, and Your account

| Message | Where |
|---|---|
| Please enter a valid email address | Auth.php · start |
| Too many codes asked for. Please wait a while and try again. | Auth.php · start |
| Sorry, we couldn't send the email just now. Please try again later. | Auth.php · start |
| That code has expired. Please ask for a new one. | Auth.php · verify |
| Too many wrong tries. Please ask for a new code. | Auth.php · verify |
| That code isn't right. Check the email and try again. | Auth.php · verify |
| Names are 1–20 letters, numbers, spaces, - or _ / Please choose a different name / That name is taken. Please choose another. | Auth.php · checkName, createUser, rename |
| Welcome, {name}! Start saying words. | app.js · verify |
| Please sign in to play | api.php · route |
| Signed out. | app.js · menu-signout |
| Your account / New private world / New competition / Sign out *(the menu under your name)* | index.html |
| Done: we won't send you the daily email any more. *(from the email's link)* | app.js · (on opening the page) |
| That link has expired. *(an old stop-email link)* | api.php · route |
| ← Back to the big world · Your account · {email} · {Plan} plan · Worlds / Games / Word packs / Settings | index.html, app.js · renderSettings |
| **Worlds:** Playing now · Ended: yours to keep or delete | app.js · renderMyWorlds |
| {world} Yours · Room / Private world / Competition · {pack} *(a card)* | app.js · worldCard |
| {place} of {n} / Team {team} / – not played yet · {n} your points / {n} players · {n} words said (· {n}% left, once below 100%) | app.js · worldCard |
| Last word {time} ago / No words yet / Starts {when} / Ends in {time} · {n} players / Ended. Open it to delete it. · Open | app.js · worldCard |
| {team} won, {n} to {n} / {team} won with {n} / A draw, {n} each *(an ended competition's result, on its card and in Past games)* | app.js · teamsResult |
| Ran · You ran this competition · {result} · ended {date} *(Past games, for a competition you ran without playing)* | app.js · renderMyGames |
| You're not in any rooms, private worlds or competitions yet. Rooms are worlds of 60 players that last for weeks; private worlds and competitions need an invite link from whoever runs them. · Play the Big World | app.js · renderMyWorlds |
| **Games:** {n} days in a row / day in a row | app.js · renderMyGames |
| Say a word today to start a new run. Your best is {n} days. / You've played today. Your best is {n} days. / Say a word today to make it {n}. Your best is {n} days. | app.js · renderMyGames |
| Your bests: {medal} {place} best finish · {n} wins / {n} best score / {word} best word (+{n}) / {n} words said in all | app.js · renderMyGames |
| Past games: {name} · {place} of {n} · Team {team} ({place} of {n}) · {n} words · best: {word} (+{n}) · ended {date} · {n} points | app.js · renderMyGames |
| No finished games yet. Big world rounds finish at the end of each round, and rooms when their words run out. So far you've said {n} words. | app.js · renderMyGames |
| **Word packs:** Your own themed words for your private worlds and competitions: a topic you teach, your company's products, a quiz theme. · {pack} · {n} words · Players suggested {n} words · Edit · New pack | index.html, app.js · renderPacks |
| **Settings:** Name · {name} · what other players see · Change | index.html |
| Repeating a word someone else said costs 2 points (it's free otherwise) *(a tick box when creating a private world or competition, and in their settings)* | index.html |
| Daily email · Each morning, when the big world starts afresh, a reminder if you haven't played yet *(an on/off switch)* | index.html |
| Daily email on: each morning we'll email you unless you've already played. Turn it off here, or from the link in any email. / Daily email off. | app.js · acct-reminders |
| Plan · Free: the big world, rooms, and worlds you're invited to / Host: also your own private worlds / Organiser: also competitions with teams, and your own word packs | app.js · PLAN_NAMES |
| Sign out · On this device | index.html |
| Delete account · Your email and name are removed. Your words stay said, as a former player. · Delete… | index.html |
| Change your name · It changes in the worlds you're playing now. Finished games keep the name you finished with. · Save name | index.html |
| Your name is now {name}. | app.js · name-form |
| Delete your account? · Your email address and name are removed, and you're signed out everywhere. · Words you said stay said, and your scores stay on leaderboards as “Former player”. · Private worlds and competitions you run end now, and your word packs are deleted. *(paid plans)* · This can't be undone. You can sign up again with the same email, as a new player. · Type delete to confirm · Delete my account | index.html |
| Confirm that you want to delete your account | api.php · route |
| Your account has been deleted. Thanks for playing. | app.js · (after deleting) |
| Former player *(a deleted account's name in worlds, with a number if needed)* | Auth.php · deleteAccount |

## 12. Emails

| Message | Where |
|---|---|
| **Sign-in:** subject “Your Unsaid code: {code}” | Auth.php · start |
| Your code for Unsaid is: {code} / Type it into the game to sign in. It works for 15 minutes. / If you didn't ask for this, you can ignore this email: nobody can sign in without the code. | Auth.php · start |
| **Daily email:** subject “Unsaid: a new day of words” | Reminders.php · send |
| Hi {name}, / The big world has started afresh: every word is unsaid again, and everyone starts level. | Reminders.php · send |
| You've played {n} days in a row. Say a word today to keep it going. | Reminders.php · send |
| Last round you came {place} of {n} with {n} points. | Reminders.php · send |
| Play: {site} / You asked for this daily email. To stop it: {link} | Reminders.php · send |

## 13. How long messages stay

Messages under the word box fade on their own: good news after 6 seconds, problems after 10,
20 when there's a Report it button, and not while the pointer or keyboard is on them. Those
times are `FEEDBACK_MS` and `keepFeedback(20000)` in app.js.

## 14. Rare and technical

| Message | Where |
|---|---|
| Something went wrong *(the prompt card, when the page can't load)* / World not found / Private world | app.js · refresh |
| The game's server isn't responding properly (HTTP {n}). Visit /check.php to find out why. | app.js · request |
| Something went wrong on the server. Visit /check.php, or see the error_log file. | api.php |
| Missing config.php: copy config.example.php and fill in your database details. Visit /check.php for help. | api.php |
| Join this world first / You've been removed from this world / Could not join this world | Game.php · playerByToken, joinAsUser |
| Nicknames are 1–20 letters, numbers, spaces, - or _ / Please choose a different nickname / That nickname is taken in this world *(from before accounts)* | Game.php · join |
