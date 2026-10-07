<?php
declare(strict_types=1);

final class Dictionary
{
    /** @var string[] words, most common first */
    public readonly array $words;
    /** @var array<string, int> word => frequency rank */
    private array $rank;
    /** @var array<string, string> word => family root, for words in a family */
    private array $familyOf = [];
    /** @var array<string, string[]> root => members */
    private array $families;
    /** @var array<string, array{label: string, words: string[]}> */
    private array $categories;
    /** @var array<string, array<string, true>> category id => set of member words */
    private array $categorySets = [];
    /** @var array<string, true> */
    private array $blocked;

    /**
     * @param string[] $words
     * @param array<string, string[]> $families
     * @param array<string, array{label: string, words: string[]}> $categories
     * @param string[] $blocked
     */
    public function __construct(array $words, array $families, array $categories = [], array $blocked = [])
    {
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
        return new self($data['words'], $data['families'], $data['categories'] ?? [], $data['blocked'] ?? []);
    }

    /** Builds a dictionary directly from a word list (used by tests). */
    public static function fromWords(array $words, array $categories = [], array $blocked = []): self
    {
        return new self($words, WordFamilies::build($words), $categories, $blocked);
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

    /** @return string[] the category's words, most common first */
    public function categoryWords(string $id): array
    {
        return $this->categories[$id]['words'] ?? [];
    }

    public function inCategory(string $id, string $word): bool
    {
        $this->categorySets[$id] ??= array_fill_keys($this->categoryWords($id), true);
        return isset($this->categorySets[$id][$word]);
    }
}
