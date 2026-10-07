// Prompts are letter patterns: words that start with, end with, or contain some letters.

export const PROMPT_TYPES = ['starts', 'ends', 'contains'];

export function matches(prompt, word) {
  switch (prompt.type) {
    case 'starts': return word.startsWith(prompt.letters);
    case 'ends': return word.endsWith(prompt.letters);
    case 'contains': return word.includes(prompt.letters);
    default: throw new Error(`Unknown prompt type: ${prompt.type}`);
  }
}

export function describe(prompt) {
  const letters = prompt.letters.toUpperCase();
  switch (prompt.type) {
    case 'starts': return `Starts with “${letters}”`;
    case 'ends': return `Ends with “${letters}”`;
    case 'contains': return `Contains “${letters}”`;
    default: return letters;
  }
}

const key = (type, letters) => `${type}:${letters}`;

// Index of every possible prompt (2-3 letters) to the dictionary words that fit it.
export class PromptIndex {
  constructor(dictionary) {
    this.fitting = new Map();
    const add = (type, letters, word) => {
      const k = key(type, letters);
      if (!this.fitting.has(k)) this.fitting.set(k, new Set());
      this.fitting.get(k).add(word);
    };
    for (const word of dictionary.words) {
      for (const n of [2, 3]) {
        if (word.length <= n) continue;
        add('starts', word.slice(0, n), word);
        add('ends', word.slice(-n), word);
        for (let i = 0; i + n <= word.length; i++) add('contains', word.slice(i, i + n), word);
      }
    }
    this.keys = [...this.fitting.keys()];
  }

  wordsFor(prompt) {
    return this.fitting.get(key(prompt.type, prompt.letters)) ?? new Set();
  }

  countUnburned(prompt, isBurned) {
    let count = 0;
    for (const word of this.wordsFor(prompt)) if (!isBurned(word)) count++;
    return count;
  }

  // Picks a random unused prompt with a playable number of unburned words.
  pick({ isBurned, used = new Set(), min = 15, max = 200, rng = Math.random }) {
    const order = [...this.keys];
    for (let i = order.length - 1; i > 0; i--) {
      const j = Math.floor(rng() * (i + 1));
      [order[i], order[j]] = [order[j], order[i]];
    }
    let fallback = null;
    for (const k of order) {
      if (used.has(k)) continue;
      const [type, letters] = k.split(':');
      const prompt = { type, letters };
      const count = this.countUnburned(prompt, isBurned);
      if (count >= min && count <= max) return { ...prompt, available: count };
      if (count > 0 && (!fallback || count > fallback.available)) fallback = { ...prompt, available: count };
    }
    // Late in a world nothing may be in range; take the richest prompt left.
    return fallback;
  }
}

export const promptKey = (prompt) => key(prompt.type, prompt.letters);
