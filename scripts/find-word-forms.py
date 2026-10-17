"""Writes data/verb-forms.txt, data/comparative-forms.txt and data/plural-forms.txt: forms of
dictionary words, with the word.

verb-forms.txt: each -ing and -ed form with the verb it's a form of. A verb's forms are one
answer with it and are judged as it: "running" scores as "run", is 3 letters long, and doesn't
end in G (except on a meaning prompt whose list names the word, such as "swimming" for "A
sport"). The family rules join forms to roots by their endings alone, which goes wrong
("browsed" to "brow", "wasted" to "wast"), so the links come from WordNet's lemmatiser instead,
and only where the root is in the dictionary.

comparative-forms.txt: each -er and -est form of an adjective (bigger, biggest), with it. They're
treated as verb forms are: one answer with the adjective, judged and scored as it on letter
prompts. The links come from WordNet's lemmatiser too ("number" isn't more "numb").

plural-forms.txt: plurals of dictionary words that the dictionary lacks ("blurbs"). A plural is
judged and scored as its singular, so adding one costs nothing.

Both add words the dictionary lacks, from ENABLE, but only ones film subtitles use at least
MIN_COUNT times: that keeps "heckled" and "creasing" and leaves out "plained" and "heliums".

Needs NLTK, so it only runs on a development machine; the output is committed:

    pip install nltk
    python -c "import nltk; nltk.download('wordnet')"
    php scripts/build-dictionary.php          (so data/dictionary.php is current)
    python scripts/find-word-forms.py
    php scripts/build-dictionary.php

A form that should stand on its own (a noun first: "earring") goes in data/family-breaks.txt,
which this script then ignores.
"""
import json
import os
import subprocess
import urllib.request

from nltk.corpus import wordnet as wn

ENABLE_URL = "https://raw.githubusercontent.com/dolph/dictionary/master/enable1.txt"
FREQ_URL = "https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/en/en_full.txt"
MIN_COUNT = 10
# Words the comparative rules would add that are other words or not words: a backhander isn't
# more backhand. (Words already in the dictionary go in family-breaks.txt instead.)
NOT_COMPARATIVES = set("""backhander busher compounder diffuser doubler easterner flusher forwarder
    golder hexer ironer laster lefter liker likest mostest netter punker reverser righter rightest
    romancer sizer strayer swaggerer teener""".split())
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "data")


def dictionary_words():
    # The built dictionary (data/dictionary.php), as PHP is the only reader of it, without the
    # forms an earlier run of this script added: each run decides those afresh.
    code = "$d = require $argv[1]; echo json_encode(array_values(array_diff($d['words'], $d['addedForms'] ?? [])));"
    out = subprocess.run(["php", "-r", code, os.path.join(DATA, "dictionary.php")], capture_output=True, text=True, check=True)
    return set(json.loads(out.stdout))


def read_list(name):
    with open(os.path.join(DATA, name), encoding="utf-8") as f:
        return [line.split("#")[0].strip().lower() for line in f if line.split("#")[0].strip()]


def regular_forms(root):
    """The spellings a regular -ing or -ed form of root could have."""
    forms = {root + "ing", root + "ed"}
    if root.endswith("e"):
        forms |= {root[:-1] + "ing", root + "d"}
    if root.endswith("ie"):
        forms.add(root[:-2] + "ying")
    if root.endswith("y") and len(root) > 1 and root[-2] not in "aeiou":
        forms.add(root[:-1] + "ied")
    if len(root) > 1 and root[-1] not in "aeiouwxy":
        forms |= {root + root[-1] + "ing", root + root[-1] + "ed"}  # stop, stopping; travel, travelling
    if root.endswith("c"):
        forms |= {root + "king", root + "ked"}  # picnic, picnicking
    return forms


def regular_comparatives(root):
    """The spellings a regular -er or -est form of root could have."""
    forms = {root + "er", root + "est"}
    if root.endswith("e"):
        forms |= {root + "r", root + "st"}
    if root.endswith("y") and len(root) > 1 and root[-2] not in "aeiou":
        forms |= {root[:-1] + "ier", root[:-1] + "iest"}
    if len(root) > 2 and root[-1] not in "aeiouwxy" and root[-2] in "aeiou" and root[-3] not in "aeiou":
        forms |= {root + root[-1] + "er", root + root[-1] + "est"}  # big, bigger; sad, saddest
    return forms


def singular_of(plural, words):
    """The dictionary word a regular plural is formed from, or None."""
    # The smallest change first: "browses" is browse's, not brows's.
    candidates = []
    if plural.endswith("s") and not plural.endswith("ss"):
        candidates.append(plural[:-1])
    if plural.endswith("ies"):
        candidates.append(plural[:-3] + "y")
    # -es only follows s, x, z, ch, sh and o (boxes, tomatoes): "filles" isn't fill's.
    if plural.endswith("es") and plural[:-2].endswith(("s", "x", "z", "ch", "sh", "o")):
        candidates.append(plural[:-2])
    return next((c for c in candidates if c in words and len(c) >= 3), None)


def write(name, header, links):
    with open(os.path.join(DATA, name), "w", encoding="utf-8", newline="\n") as f:
        f.write(header)
        for form, root in sorted(links.items()):
            f.write(f"{form} {root}\n")


def main():
    words = dictionary_words()
    breaks = set(read_list("family-breaks.txt"))
    enable = urllib.request.urlopen(ENABLE_URL).read().decode().split()
    new = set(enable) - words
    counts = {}
    with urllib.request.urlopen(FREQ_URL) as f:
        for line in f:
            word, _, count = line.decode("utf-8", "replace").strip().partition(" ")
            if word in new:
                counts[word] = int(count or 0)
    common = lambda w: w in words or counts.get(w, 0) >= MIN_COUNT

    verb_forms = {}
    for form in sorted(words | new):
        if form in breaks or len(form) < 4 or not (form.endswith("ing") or form.endswith("ed")) or not common(form):
            continue
        root = wn.morphy(form, wn.VERB)
        if root and root != form and root in words and form in regular_forms(root):
            verb_forms[form] = root
    write("verb-forms.txt",
          "# Generated by scripts/find-word-forms.py: each -ing and -ed form, then its verb.\n"
          "# A form not in the dictionary is added beside its verb. Don't edit by hand: to let a\n"
          "# form stand on its own, add it to family-breaks.txt and run the script again.\n",
          verb_forms)

    comparatives = {}
    for form in sorted(words | new):
        if form in breaks or form in NOT_COMPARATIVES or form in verb_forms or len(form) < 4 or not (form.endswith("er") or form.endswith("est")) or not common(form):
            continue
        roots = [r for r in wn._morphy(form, wn.ADJ) + wn._morphy(form, wn.ADV)
                 if r != form and r in words and form in regular_comparatives(r)]
        if roots:
            comparatives[form] = max(roots, key=len)
    write("comparative-forms.txt",
          "# Generated by scripts/find-word-forms.py: each -er and -est form, then its adjective.\n"
          "# A form not in the dictionary is added beside it. Don't edit by hand: to let a form stand\n"
          "# on its own, add it to family-breaks.txt (or own-meaning.txt) and run the script again.\n",
          comparatives)

    plurals = {}
    for plural in sorted(new):
        if plural in breaks or plural in verb_forms or plural in comparatives or not plural.endswith("s") or not common(plural):
            continue
        singular = singular_of(plural, words)
        if singular:
            plurals[plural] = singular
    write("plural-forms.txt",
          "# Generated by scripts/find-word-forms.py: plurals the dictionary lacks, then their\n"
          "# singular. Each is added beside its singular, and judged and scored as it. Don't edit by hand.\n",
          plurals)
    added = sum(1 for f in verb_forms if f not in words)
    print(f"Wrote data/verb-forms.txt: {len(verb_forms)} forms, {added} of them new to the dictionary")
    print(f"Wrote data/comparative-forms.txt: {len(comparatives)} forms, {sum(1 for f in comparatives if f not in words)} of them new to the dictionary")
    print(f"Wrote data/plural-forms.txt: {len(plurals)} plurals")


main()
