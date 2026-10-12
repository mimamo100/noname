"""Drafts a NEW meaning category from WordNet, as data/categories/<id>.txt.

The categories are kept by hand (see data/categories/README.md). WordNet is only a
starting point for a new one: run this once, then edit the draft before committing it.
It never overwrites an existing category. Needs NLTK, so it only runs on a development
machine:

    pip install nltk
    python -c "import nltk; nltk.download('wordnet')"
    python scripts/build-categories.py herb "A herb, spice or sauce" flavorer.n.01
    php scripts/build-dictionary.php

The draft has two lists:
  main   clear answers: the word's main meaning fits (its most-used sense if it's
         mostly a noun, or its first listed sense if WordNet has seen it in real
         text). These size prompts and are counted as "left unsaid".
  also   accepted too, because some other meaning fits ("squash" is a sport, though
         WordNet lists the vegetable first). Not counted, so obscure meanings don't
         inflate the counter.
Expect to move everyday answers from also to main, remove odd members, and add
answers WordNet files elsewhere.
"""
import os
import sys
from nltk.corpus import wordnet as wn

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def lemma_count(synset, base):
    return sum(l.count() for l in synset.lemmas() if l.name() == base)


def pos_count(word, pos):
    base = wn.morphy(word, pos)
    if not base:
        return 0, None
    return sum(lemma_count(s, base) for s in wn.synsets(base, pos)), base


def main_senses(word):
    """The noun senses that count as the word's main meaning."""
    noun, base = pos_count(word, wn.NOUN)
    if not base:
        return set()
    synsets = wn.synsets(base, wn.NOUN)
    senses = set()
    if lemma_count(synsets[0], base) > 0:  # First listed sense, seen in real text: "fly" the insect.
        senses.add(synsets[0])
    others = max(pos_count(word, p)[0] for p in (wn.VERB, wn.ADJ, wn.ADV))
    has_other_pos = any(pos_count(word, p)[1] for p in (wn.VERB, wn.ADJ, wn.ADV))
    if noun >= others and not (has_other_pos and noun == 0):  # Mostly a noun: "does" and "must" aren't.
        best = max(synsets, key=lambda s: lemma_count(s, base))
        senses.add(best if lemma_count(best, base) > 0 else synsets[0])
    return senses


def all_senses(word):
    """Every noun sense of the word, for accepting answers: "squash" the sport, not just the vegetable."""
    base = wn.morphy(word, wn.NOUN)
    return set(wn.synsets(base, wn.NOUN)) if base else set()


def read_words(name):
    with open(os.path.join(ROOT, 'data', name), encoding='utf-8') as f:
        return [w for w in (line.split('#')[0].strip().lower() for line in f) if w]


def main():
    if len(sys.argv) < 4:
        sys.exit('Usage: python scripts/build-categories.py <id> "<label>" <synset> [<synset>...]')
    cat_id, label, roots = sys.argv[1], sys.argv[2], sys.argv[3:]
    path = os.path.join(ROOT, 'data', 'categories', cat_id + '.txt')
    if os.path.exists(path):
        sys.exit(f'data/categories/{cat_id}.txt already exists. Edit it by hand instead.')
    words = list(dict.fromkeys(read_words('words.txt') + read_words('extra-words.txt')))
    kinds = set()
    for name in roots:
        root = wn.synset(name)
        kinds |= {root} | set(root.closure(lambda s: s.hyponyms()))
    members = [w for w in words if main_senses(w) & kinds]
    member_set = set(members)
    also = [w for w in words if w not in member_set and all_senses(w) & kinds]
    with open(path, 'w', encoding='utf-8', newline='\n') as f:
        f.write(f'label: {label}\n\n' + '\n'.join(sorted(members)) + '\n\nalso:\n' + '\n'.join(sorted(also)) + '\n')
    print(f'Wrote data/categories/{cat_id}.txt: {len(members)} main, {len(also)} also. Edit it before committing.')


if __name__ == '__main__':
    main()
