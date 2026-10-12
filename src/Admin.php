<?php
declare(strict_types=1);

/**
 * The admin page's data: reported words, accounts and plans, and a few stats.
 *
 * Reports are a queue, never a change to the game: the word files in data/ are the only
 * source of truth. The admin downloads the open reports, approved words go into the files,
 * the dictionary is rebuilt and uploaded, and then marking the reports reviewed closes them.
 * A report whose word now counts shows its player "accepted".
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
            'accounts' => $count('SELECT COUNT(*) FROM users'),
            'worlds' => $count('SELECT COUNT(*) FROM worlds'),
            // People who played (guessed or joined a world) in the last day and week. Each account
            // counts once however many worlds it played in; players from before accounts count singly.
            'activeToday' => $this->activeSince($this->now() - 24 * 60 * 60 * 1000),
            'activeWeek' => $this->activeSince($this->now() - 7 * 24 * 60 * 60 * 1000),
            'saidToday' => $count('SELECT COUNT(*) FROM burns WHERE word = played_word AND burned_at > ?', [$since]),
            'missesToday' => $count('SELECT COUNT(*) FROM misses WHERE missed_at > ?', [$since]),
            'openReports' => count(array_filter($this->openReports(), fn ($r) => !$r['counts'])),
        ];
    }

    private function activeSince(int $since): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT user_id) + COALESCE(SUM(user_id IS NULL), 0) FROM players WHERE last_active_at > ?');
        $stmt->execute([$since]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Reported words not yet reviewed, most-reported first, each with whether it already
     * counts (once the word files have been updated and uploaded).
     */
    public function openReports(): array
    {
        $rows = $this->db->query("
            SELECT r.category, r.word, COUNT(*) AS reports, COUNT(DISTINCT r.player_id) AS players,
              MIN(r.reported_at) AS first_reported, MAX(r.reported_at) AS last_reported,
              SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT r.prompt_id ORDER BY r.prompt_id DESC), ',', 3) AS prompt_ids
            FROM reports r
            WHERE r.reviewed_at IS NULL
            GROUP BY r.category, r.word
            ORDER BY players DESC, last_reported DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => [
            'category' => $r['category'],
            'categoryLabel' => $this->categoryLabel($r['category']),
            'word' => $r['word'],
            'reports' => (int) $r['reports'],
            'players' => (int) $r['players'],
            'firstReported' => (int) $r['first_reported'],
            'lastReported' => (int) $r['last_reported'],
            'prompts' => array_values(array_unique(array_map(fn ($id) => $this->promptLabel((int) $id), explode(',', (string) $r['prompt_ids'])))),
            'inDictionary' => $this->dictionary->has($r['word']),
            'counts' => $r['category'] === self::DICTIONARY_ONLY
                ? $this->dictionary->has($r['word'])
                : $this->dictionary->inCategory($r['category'], $r['word']),
        ], $rows);
    }

    /**
     * The open reports as a text file to work through (by hand or in a Claude session), grouped
     * by category, plus any words accepted on the admin page before the word files became the
     * only source of truth that the files don't have yet.
     */
    public function reportsText(): string
    {
        $date = fn (int $ms) => date('j M Y', intdiv($ms, 1000));
        $out = "# Unsaid: reported words, downloaded " . $date($this->now()) . "\n"
            . "#\n"
            . "# Players reported these as answers our lists are missing. Approved words go into\n"
            . "# data/categories/<id>.txt (see data/categories/README.md), or data/extra-words.txt for\n"
            . "# words missing from the dictionary; then rebuild with php scripts/build-dictionary.php\n"
            . "# and upload data/dictionary.php. Afterwards press Mark all reviewed on the admin page.\n"
            . "#\n"
            . "# Each line: word, how many players reported it, when, and the prompts it was for.\n";
        $groups = [];
        foreach ($this->openReports() as $r) {
            if ($r['counts']) continue; // Already in the files.
            $key = $r['category'] === self::DICTIONARY_ONLY ? 'dictionary' : $r['category'];
            $groups[$key][] = $r;
        }
        ksort($groups);
        if (!$groups) $out .= "\nNo open reports.\n";
        foreach ($groups as $key => $reports) {
            $out .= $key === 'dictionary'
                ? "\n## Not in the dictionary (extra-words.txt)\n"
                : "\n## $key: {$reports[0]['categoryLabel']} (categories/$key.txt)\n";
            foreach ($reports as $r) {
                $out .= sprintf("%-20s %d %s, %s%s\n",
                    $r['word'], $r['players'], $r['players'] === 1 ? 'player' : 'players', $date($r['lastReported']),
                    $key === 'dictionary' ? '' : ' (for: ' . implode('; ', $r['prompts']) . ')' . ($r['inDictionary'] ? '' : ' NOT IN DICTIONARY'));
            }
        }
        $legacy = $this->legacyAccepted();
        if ($legacy) {
            $out .= "\n## Accepted on the admin page earlier, not yet in the files\n"
                . "# These counted while the old live acceptance existed. Add them to the files to keep them.\n";
            foreach ($legacy as $d) {
                $out .= sprintf("%-20s %s\n", $d['word'], $d['category'] === self::DICTIONARY_ONLY ? 'dictionary' : "{$d['category']}: {$d['categoryLabel']}");
            }
        }
        return $out;
    }

    /** Words accepted under the old live acceptance (the word_decisions table) that the files lack. */
    public function legacyAccepted(): array
    {
        try {
            $rows = $this->db->query("SELECT category, word FROM word_decisions WHERE decision = 'accepted' ORDER BY category, word")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            return []; // A database from after that table was retired.
        }
        $missing = array_filter($rows, fn ($r) => !($r['category'] === self::DICTIONARY_ONLY
            ? $this->dictionary->has($r['word'])
            : $this->dictionary->hasCategory($r['category']) && $this->dictionary->inCategory($r['category'], $r['word'])));
        return array_values(array_map(fn ($r) => $r + ['categoryLabel' => $this->categoryLabel($r['category'])], $missing));
    }

    /** Closes every open report: the ones the files now cover show as accepted, the rest as reviewed. */
    public function markReviewed(): int
    {
        $stmt = $this->db->prepare('UPDATE reports SET reviewed_at = ? WHERE reviewed_at IS NULL');
        $stmt->execute([$this->now()]);
        return $stmt->rowCount();
    }

    /**
     * Accounts matching a name or email (part of either), or with no search, every account on
     * a paid plan. Newest first, at most 50.
     */
    public function accounts(string $search = ''): array
    {
        $search = trim($search);
        if ($search === '') {
            $stmt = $this->db->query("SELECT id, name, email, plan, created_at FROM users WHERE plan <> 'free' ORDER BY id DESC LIMIT 50");
        } else {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $stmt = $this->db->prepare('SELECT id, name, email, plan, created_at FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$like, $like]);
        }
        return array_map(fn ($u) => [
            'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'plan' => $u['plan'], 'createdAt' => (int) $u['created_at'],
        ], $stmt->fetchAll());
    }

    /** Sets an account's plan by hand, until payments do it. */
    public function setPlan(int $userId, string $plan): string
    {
        if (!in_array($plan, PrivateWorlds::PLANS, true)) throw new GameError(400, 'Unknown plan');
        $stmt = $this->db->prepare('UPDATE users SET plan = ? WHERE id = ?');
        $stmt->execute([$plan, $userId]);
        $name = $this->db->prepare('SELECT name FROM users WHERE id = ?');
        $name->execute([$userId]);
        return $name->fetchColumn() ?: throw new GameError(404, 'No such account');
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
