# Burned Words

> Every word anyone plays is gone. For everyone. Forever.

A word game where each world starts with the same dictionary of about 32,000
common English words. Players type words that fit the current prompt (such as
*contains "OO"*). Every word played is burned, together with its family
(*cat* burns *cats*), so nobody in that world can use it again. As the
language shrinks, scores rise, so clever late players can still catch up.

The full design is in [docs/DESIGN.md](docs/DESIGN.md).

## Running it

Requires Node.js 22.13 or later. There are no dependencies to install.

```sh
npm start        # http://localhost:3000
npm test
```

- `/` is the public world. Use **New private world** to create one and share
  its link.
- Game data is stored in SQLite at `var/burned-words.db`. Set `DATA_DIR` to
  change the folder and `PORT` to change the port.

## Project layout

| Path | What it is |
|---|---|
| `src/dictionary.js` | Word list, rarity ranks and word families |
| `src/prompts.js` | Prompt patterns and the prompt picker |
| `src/scoring.js` | Points and multipliers |
| `src/game.js` | Worlds, players, burning and rotation (SQLite) |
| `src/server.js` | HTTP API and static file server |
| `public/` | The browser client |
| `scripts/build-dictionary.mjs` | Regenerates `data/words.txt` |

## Word list credits

`data/words.txt` combines two sources:
- [ENABLE](https://github.com/dolph/dictionary) word list (public domain)
- [FrequencyWords](https://github.com/hermitdave/FrequencyWords) by Hermit Dave,
  built from OpenSubtitles (CC BY-SA 4.0)

The word list is therefore shared under CC BY-SA 4.0.
