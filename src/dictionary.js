import { readFileSync } from 'node:fs';

const DEFAULT_PATH = new URL('../data/words.txt', import.meta.url);

const isConsonant = (ch) => ch !== undefined && !'aeiouy'.includes(ch);

// Candidate roots for -ed / -ing stems, most likely first.
function verbRoots(stem, allowSilentE) {
  if (stem.length < 3) return [];
  const [a, b] = [stem.at(-2), stem.at(-1)];
  // running -> run, stopped -> stop (but falling -> fall comes first).
  if (a === b && isConsonant(b)) return [stem, stem.slice(0, -1)];
  // singing -> sing, not singe.
  if (isConsonant(a) && isConsonant(b)) return [stem];
  // hoping -> hope, not hop; rated -> rate, not rat.
  return allowSilentE ? [stem + 'e', stem] : [stem];
}

// Candidate roots for a word from simple suffix rules (plurals, -ed, -ing), most likely first.
function rootCandidates(word) {
  const candidates = [];
  if (word.endsWith('ies')) candidates.push(word.slice(0, -3) + 'y');
  if (word.endsWith('s') && !word.endsWith('ss')) candidates.push(word.slice(0, -1)); // rates -> rate
  if (word.endsWith('es')) candidates.push(word.slice(0, -2)); // boxes -> box
  if (word.endsWith('ied')) candidates.push(word.slice(0, -3) + 'y');
  // -d is only stripped from longer words, so seed doesn't become see or shed become she.
  if (word.endsWith('ed')) candidates.push(...verbRoots(word.slice(0, -2), word.length >= (word.endsWith('eed') ? 6 : 5)));
  if (word.endsWith('ing')) candidates.push(...verbRoots(word.slice(0, -3), true));
  return candidates.filter((c) => c.length >= 3);
}

export class Dictionary {
  constructor(words) {
    this.words = words;
    this.rank = new Map(words.map((w, i) => [w, i]));
    this.familyOf = new Map();
    this.members = new Map();
    for (const word of words) {
      const root = rootCandidates(word).find((c) => c !== word && this.rank.has(c)) ?? word;
      // Follow one more step so chains collapse onto a single root.
      const key = this.familyOf.get(root) ?? root;
      this.familyOf.set(word, key);
    }
    // Roots may be processed after their inflections; normalise every entry.
    for (const word of words) {
      let key = this.familyOf.get(word);
      while (this.familyOf.get(key) !== key) key = this.familyOf.get(key);
      this.familyOf.set(word, key);
      if (!this.members.has(key)) this.members.set(key, []);
      this.members.get(key).push(word);
    }
  }

  static load(path = DEFAULT_PATH) {
    const words = readFileSync(path, 'utf8').split('\n').map((w) => w.trim()).filter(Boolean);
    return new Dictionary(words);
  }

  get size() {
    return this.words.length;
  }

  has(word) {
    return this.rank.has(word);
  }

  family(word) {
    return this.members.get(this.familyOf.get(word)) ?? [];
  }

  // 0 for the most common word, approaching 1 for the rarest.
  rarity(word) {
    return this.rank.get(word) / this.size;
  }
}
