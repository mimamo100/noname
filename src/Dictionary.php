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

    /**
     * @param string[] $words
     * @param array<string, string[]> $families
     */
    public function __construct(array $words, array $families)
    {
        $this->words = $words;
        $this->rank = array_flip($words);
        $this->families = $families;
        foreach ($families as $root => $members) {
            foreach ($members as $member) $this->familyOf[$member] = (string) $root;
        }
    }

    public static function load(?string $path = null): self
    {
        $data = require $path ?? dirname(__DIR__) . '/data/dictionary.php';
        return new self($data['words'], $data['families']);
    }

    /** Builds a dictionary directly from a word list (used by tests). */
    public static function fromWords(array $words): self
    {
        return new self($words, WordFamilies::build($words));
    }

    public function size(): int
    {
        return count($this->words);
    }

    public function has(string $word): bool
    {
        return isset($this->rank[$word]);
    }

    /** @return string[] the word and every other word in its family */
    public function family(string $word): array
    {
        if (!$this->has($word)) return [];
        return isset($this->familyOf[$word]) ? $this->families[$this->familyOf[$word]] : [$word];
    }

    /** 0 for the most common word, approaching 1 for the rarest. */
    public function rarity(string $word): float
    {
        return $this->rank[$word] / $this->size();
    }
}
