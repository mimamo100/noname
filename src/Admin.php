<?php
declare(strict_types=1);

/**
 * The admin page's data: reported words, decisions about them, and a few stats.
 *
 * Accepting a word stores a decision in the word_decisions table. Game::applyWordDecisions()
 * adds accepted words to the dictionary on every request, so they work straight away
 * without rebuilding data/dictionary.php.
 */
final class Admin
{
    /** Category used for reports and decisions about the dictionary itself, not a meaning. */
    public const DICTIONARY_ONLY = '';

    public function __construct(private readonly PDO $db, private readonly Dictionary $dictionary, private $clock = null)
    {
        $this->clock ??= fn () => (int) floor(microtime(true) * 1000);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    public function stats(): array
    {
        $since = $this->now() - 24 * 60 * 60 * 1000;
        $count = function (string $sql, array $args = []): int {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($args);
            return (int) $stmt->fetchColumn();
        };
        return [
            'worlds' => $count('SELECT COUNT(*) FROM worlds'),
            'players' => $count('SELECT COUNT(*) FROM players'),
            'saidToday' => $count('SELECT COUNT(*) FROM burns WHERE word = played_word AND burned_at > ?', [$since]),
            'missesToday' => $count('SELECT COUNT(*) FROM misses WHERE missed_at > ?', [$since]),
            'openReports' => count($this->openReports()),
        ];
    }

    /** Reported words without a decision yet, most-reported first. */
    public function openReports(): array
    {
        $rows = $this->db->query("
            SELECT r.category, r.word, COUNT(*) AS reports, COUNT(DISTINCT r.player_id) AS players,
              MAX(r.reported_at) AS last_reported,
              SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT r.prompt_id ORDER BY r.prompt_id DESC), ',', 3) AS prompt_ids
            FROM reports r
            LEFT JOIN word_decisions d ON d.category = r.category AND d.word = r.word
            WHERE d.word IS NULL
            GROUP BY r.category, r.word
            ORDER BY reports DESC, last_reported DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => [
            'category' => $r['category'],
            'categoryLabel' => $this->categoryLabel($r['category']),
            'word' => $r['word'],
            'reports' => (int) $r['reports'],
            'players' => (int) $r['players'],
            'lastReported' => (int) $r['last_reported'],
            'prompts' => array_values(array_unique(array_map(fn ($id) => $this->promptLabel((int) $id), explode(',', (string) $r['prompt_ids'])))),
            'inDictionary' => $this->dictionary->has($r['word']),
        ], $rows);
    }

    /** Every decision (accepted words and dismissed reports), newest first. */
    public function decisions(): array
    {
        $rows = $this->db->query('SELECT category, word, decision, decided_at FROM word_decisions ORDER BY decided_at DESC')
            ->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => [
            'category' => $r['category'],
            'categoryLabel' => $this->categoryLabel($r['category']),
            'word' => $r['word'],
            'decision' => $r['decision'],
            'decidedAt' => (int) $r['decided_at'],
        ], $rows);
    }

    /** Accepts a word for a category (or just the dictionary), or dismisses a report. */
    public function decide(string $category, string $rawWord, string $decision): void
    {
        $word = strtolower(trim($rawWord));
        if (!in_array($decision, ['accepted', 'dismissed'], true)) throw new GameError(400, 'Unknown decision');
        if (!preg_match('/^[a-z]{3,30}$/', $word)) throw new GameError(400, 'Words must be 3–30 letters, a to z only');
        if ($category !== self::DICTIONARY_ONLY && !$this->dictionary->hasCategory($category)) throw new GameError(400, 'Unknown category');
        if ($decision === 'accepted' && $this->dictionary->isBlocked($word)) throw new GameError(400, "“{$word}” is on the blocklist");
        $this->db->prepare('
            INSERT INTO word_decisions (category, word, decision, decided_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), decided_at = VALUES(decided_at)')
            ->execute([$category, $word, $decision, $this->now()]);
    }

    /** Forgets a decision: an accepted word stops counting, a dismissed report shows again. */
    public function undo(string $category, string $word): void
    {
        $this->db->prepare('DELETE FROM word_decisions WHERE category = ? AND word = ?')->execute([$category, $word]);
    }

    /** @return array<string, string> category id => label, for the "add a word" form */
    public function categories(): array
    {
        $out = [];
        foreach ($this->dictionary->categoryIds() as $id) $out[$id] = $this->dictionary->categoryLabel($id);
        asort($out);
        return $out;
    }

    private function promptLabel(int $id): string
    {
        $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        return $row['label'] ?? Prompts::describe(Game::specOf($row), $this->dictionary);
    }

    private function categoryLabel(string $category): string
    {
        return $category === self::DICTIONARY_ONLY ? 'Dictionary only' : $this->dictionary->categoryLabel($category);
    }
}
