<?php
declare(strict_types=1);

final class Dictionary
{
    /** @var string[] words, most common first (words added by the admin come last) */
    public array $words;
    /** @var array<string, int> word => frequency rank */
    private array $rank;
    /** @var array<string, string> word => family root, for words in a family */
    private array $familyOf = [];
    /** @var array<string, string[]> root => members */
    private array $families;
    /** @var array<string, array{label: string, words: string[], also?: string[]}> */
    private array $categories;
    /** @var array<string, array<string, true>> category id => every accepted word */
    private array $categorySets = [];
    /** @var array<string, true> */
    private array $blocked;
    /** @var array<string, true> playable, but shown masked to other players */
    private array $masked;
    /** @var array{usOnly: string[], ukOnly: string[], partners: array<string, string[]>} */
    private array $spelling;

    /**
     * @param string[] $words
     * @param array<string, string[]> $families
     * @param array<string, array{label: string, words: string[]}> $categories
     * @param string[] $blocked
     * @param string[] $masked
     */
    public function __construct(array $words, array $families, array $categories = [], array $blocked = [], array $spelling = [], array $masked = [])
    {
        $this->masked = array_fill_keys($masked, true);
        $this->spelling = $spelling + ['usOnly' => [], 'ukOnly' => [], 'partners' => []];
        $this->words = $words;
        $this->rank = array_flip($words);
        $this->families = $families;
        foreach ($families as $root => $members) {
            foreach ($members as $member) $this->familyOf[$member] = (string) $root;
        }
        $this->categories = $categories;
        $this->blocked = array_fill_keys($blocked, true);
    }

    public static function load(?string $path = null): self
    {
        $data = require $path ?? dirname(__DIR__) . '/data/dictionary.php';
        return new self($data['words'], $data['families'], $data['categories'] ?? [], $data['blocked'] ?? [], $data['spelling'] ?? [], $data['masked'] ?? []);
    }

    /**
     * A dictionary of just a word pack's words (see Packs), with its categories. The blocklist,
     * masking and UK/US spellings stay as they are.
     */
    public function onlyPack(array $pack): self
    {
        return new self($pack['words'], self::keyedFamilies($pack['families']), $pack['categories'],
            array_keys($this->blocked), $this->spelling, array_keys($this->masked));
    }

    /**
     * The everyday dictionary plus a word pack: the pack's words that are new come last (so
     * they count as rare), families are joined where the pack links forms ("nucleus nuclei"),
     * and the categories are the pack's, so its prompts are the pack's.
     */
    public function withPack(array $pack): self
    {
        $words = $this->words;
        foreach ($pack['words'] as $word) {
            if (!isset($this->rank[$word])) $words[] = $word;
        }
        $families = $this->families;
        foreach ($pack['families'] as $members) {
            $joined = $members;
            foreach ($members as $member) {
                $root = $this->familyOf[$member] ?? null;
                if ($root !== null && isset($families[$root])) {
                    array_push($joined, ...$families[$root]);
                    unset($families[$root]);
                }
            }
            $families['pack:' . $members[0]] = array_values(array_unique($joined));
        }
        return new self($words, $families, $pack['categories'], array_keys($this->blocked), $this->spelling, array_keys($this->masked));
    }

    /** @param string[][] $families */
    private static function keyedFamilies(array $families): array
    {
        $keyed = [];
        foreach ($families as $members) $keyed[$members[0]] = $members;
        return $keyed;
    }

    /** Builds a dictionary directly from a word list (used by tests). */
    public static function fromWords(array $words, array $categories = [], array $blocked = [], array $spelling = [], array $masked = []): self
    {
        $families = WordFamilies::build($words);
        // Linked spellings join one family, as the build script does.
        foreach ($spelling['partners'] ?? [] as $word => $partners) {
            foreach ($partners as $partner) {
                $a = self::findFamily($families, $word);
                $b = self::findFamily($families, $partner);
                if ($a === $b) continue;
                $families[$a] = array_values(array_unique(array_merge($families[$a] ?? [$a], $families[$b] ?? [$b])));
                unset($families[$b]);
            }
        }
        return new self($words, $families, $categories, $blocked, $spelling, $masked);
    }

    private static function findFamily(array $families, string $word): string
    {
        foreach ($families as $root => $members) if (in_array($word, $members, true)) return (string) $root;
        return $word;
    }

    /**
     * Words that can't be played in a world with this spelling: in a UK world, US-only
     * spellings like "color"; in a US world, UK-only ones like "colour".
     *
     * @return array<string, true>
     */
    public function wrongSpellings(string $spelling): array
    {
        return match ($spelling) {
            'uk' => array_fill_keys($this->spelling['usOnly'], true),
            'us' => array_fill_keys($this->spelling['ukOnly'], true),
            default => [],
        };
    }

    /** @return string[] other spellings of the word ("colour" for "color") */
    public function otherSpellings(string $word): array
    {
        return $this->spelling['partners'][$word] ?? [];
    }

    public function size(): int
    {
        return count($this->words);
    }

    public function has(string $word): bool
    {
        return isset($this->rank[$word]);
    }

    public function isBlocked(string $word): bool
    {
        return isset($this->blocked[$word]);
    }

    /** A word players can say, but that's shown masked to others (p***s). */
    public function isMasked(string $word): bool
    {
        return isset($this->masked[$word]);
    }

    /** Whether any word in a name, or the whole name squashed together, is blocked or masked. */
    public function isOffensiveName(string $name): bool
    {
        $lower = mb_strtolower($name);
        $parts = preg_split('/[^a-z]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $parts[] = preg_replace('/[^a-z]/', '', $lower);
        foreach ($parts as $part) {
            if ($this->isBlocked($part) || $this->isMasked($part)) return true;
        }
        return false;
    }

    /** @return string[] the word and every other word in its family */
    public function family(string $word): array
    {
        if (!$this->has($word)) return [];
        return isset($this->familyOf[$word]) ? $this->families[$this->familyOf[$word]] : [$word];
    }

    /** The word that names a word's family; words in the same family share it. */
    public function familyRoot(string $word): string
    {
        return $this->familyOf[$word] ?? $word;
    }

    /**
     * The singular of a plural in the dictionary ("brothers" -> "brother", "boxes" -> "box",
     * "cities" -> "city"), or the word itself. Only plurals: "-ing" and "-ed" words are often
     * words in their own right ("building", "evening").
     */
    public function singular(string $word): string
    {
        if (!str_ends_with($word, 's') || str_ends_with($word, 'ss')) return $word;
        $candidates = [substr($word, 0, -1)];
        if (str_ends_with($word, 'es')) $candidates[] = substr($word, 0, -2);
        if (str_ends_with($word, 'ies')) $candidates[] = substr($word, 0, -3) . 'y';
        foreach ($candidates as $candidate) {
            if ($this->has($candidate) && $this->familyRoot($candidate) === $this->familyRoot($word)) return $candidate;
        }
        return $word;
    }

    /** 0 for the most common word, approaching 1 for the rarest. */
    public function rarity(string $word): float
    {
        return $this->rank[$word] / $this->size();
    }

    /** @return string[] category ids */
    public function categoryIds(): array
    {
        return array_keys($this->categories);
    }

    public function categoryLabel(string $id): string
    {
        return $this->categories[$id]['label'] ?? $id;
    }

    /**
     * The category's clear answers, most common first. These size prompts and are counted
     * as "left unsaid".
     *
     * @return string[]
     */
    public function categoryWords(string $id): array
    {
        return $this->categories[$id]['words'] ?? [];
    }

    /** Whether a word is accepted for a category: a clear answer, or one with another meaning that fits ("squash"). */
    /**
     * Adds a word at runtime, from an admin decision stored in the database. It goes at the
     * end of the list, so it counts as rare. Returns false for blocked or malformed words.
     */
    public function addWord(string $word): bool
    {
        if ($this->has($word)) return true;
        if ($this->isBlocked($word) || !preg_match('/^[a-z]{3,30}$/', $word)) return false;
        $this->rank[$word] = count($this->words);
        $this->words[] = $word;
        return true;
    }

    /** Makes a word a clear answer for a category at runtime, adding it to the dictionary if needed. */
    public function addToCategory(string $id, string $word): bool
    {
        if (!isset($this->categories[$id]) || !$this->addWord($word)) return false;
        if (!in_array($word, $this->categories[$id]['words'], true)) $this->categories[$id]['words'][] = $word;
        unset($this->categorySets[$id]);
        return true;
    }

    public function hasCategory(string $id): bool
    {
        return isset($this->categories[$id]);
    }

    public function inCategory(string $id, string $word): bool
    {
        $this->categorySets[$id] ??= array_fill_keys(
            array_merge($this->categoryWords($id), $this->categories[$id]['also'] ?? []),
            true,
        );
        if (isset($this->categorySets[$id][$word])) return true;
        // A plural counts if its singular does: hand-added words (overrides, the admin page)
        // come without their plurals.
        $singular = $this->singular($word);
        return $singular !== $word && isset($this->categorySets[$id][$singular]);
    }
}
