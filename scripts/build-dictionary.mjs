// Builds data/words.txt: common English words, most frequent first.
// Words must appear in ENABLE (public domain) and in the OpenSubtitles
// frequency list from hermitdave/FrequencyWords (CC BY-SA 4.0).
// Usage: node scripts/build-dictionary.mjs [maxWords]
import { writeFileSync } from 'node:fs';

const ENABLE_URL = 'https://raw.githubusercontent.com/dolph/dictionary/master/enable1.txt';
const FREQ_URL = 'https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/en/en_50k.txt';
const MAX_WORDS = Number(process.argv[2] ?? 40000);
const MIN_LENGTH = 3;

async function fetchText(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`${url}: HTTP ${res.status}`);
  return res.text();
}

const enable = new Set((await fetchText(ENABLE_URL)).split(/\r?\n/).map((w) => w.trim()));
const words = [];
const seen = new Set();
for (const line of (await fetchText(FREQ_URL)).split(/\r?\n/)) {
  const word = line.split(' ')[0]?.trim().toLowerCase();
  if (!word || seen.has(word) || word.length < MIN_LENGTH || !/^[a-z]+$/.test(word)) continue;
  if (!enable.has(word)) continue;
  seen.add(word);
  words.push(word);
  if (words.length >= MAX_WORDS) break;
}

writeFileSync(new URL('../data/words.txt', import.meta.url), words.join('\n') + '\n');
console.log(`Wrote ${words.length} words to data/words.txt`);
