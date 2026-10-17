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
    /** Category used for reports about the dictionary itself, not a meaning. */
    public const DICTIONARY_ONLY = '';

    /**
     * What a helper may be allowed to do, as the super-admin ticks them in the Helpers tab. The
     * super-admin can do all of these, and is the only one who can manage helpers.
     */
    public const PERMISSIONS = [
        'overview' => 'See the overview: stats, emails and housekeeping',
        'reports' => 'See and download reported words',
        'review' => 'Mark reports reviewed',
        'accounts' => 'Find accounts by name',
        'emails' => 'See account emails, and find accounts by email',
        'plans' => 'Change account plans',
        'packs' => "Remove organisers' word packs",
        'maintenance' => 'Recount scores and run housekeeping',
    ];
    /** A new helper's permissions, until the super-admin changes them. */
    public const NEW_HELPER = ['overview', 'reports', 'accounts'];

    public function __construct(private readonly PDO $db, private readonly Dictionary $dictionary, private $clock = null, private readonly ?Packs $packs = null)
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

    /**
     * Emails the site has sent, against the host's sending limit (usually per hour): sign-in
     * codes in the last hour and day, the busiest hour, and how many accounts want the daily
     * email. Each sign-in code is one email.
     */
    public function emailStats(): array
    {
        $now = $this->now();
        $count = function (string $sql, array $args = []): int {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($args);
            return (int) $stmt->fetchColumn();
        };
        return [
            'codesHour' => $count('SELECT COUNT(*) FROM login_codes WHERE created_at > ?', [$now - 3_600_000]),
            'codesDay' => $count('SELECT COUNT(*) FROM login_codes WHERE created_at > ?', [$now - 86_400_000]),
            'busiestHour' => $count('SELECT COALESCE(MAX(n), 0) FROM (
                SELECT COUNT(*) AS n FROM login_codes WHERE created_at > ? GROUP BY FLOOR(created_at / 3600000)) h', [$now - 86_400_000]),
            'remindersOn' => $count('SELECT COUNT(*) FROM users WHERE reminders = 1'),
            'lastDaily' => Reminders::lastRun($this->db),
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
            SELECT r.category, r.word, COUNT(DISTINCT r.player_id) AS players, MAX(r.reported_at) AS last_reported,
              SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT r.prompt_id ORDER BY r.prompt_id DESC), ',', 3) AS prompt_ids
            FROM reports r
            WHERE r.reviewed_at IS NULL AND r.category NOT REGEXP '^p[0-9a-f]{8}[.]'
            GROUP BY r.category, r.word
            ORDER BY players DESC, last_reported DESC")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => [
            'category' => $r['category'],
            'categoryLabel' => $this->categoryLabel($r['category']),
            'word' => $r['word'],
            'players' => (int) $r['players'],
            'lastReported' => (int) $r['last_reported'],
            'prompts' => array_values(array_unique(array_map(fn ($id) => $this->promptLabel((int) $id), explode(',', (string) $r['prompt_ids'])))),
            'inDictionary' => str_contains($r['category'], '.') || $this->dictionary->has($r['word']), // Pack words needn't be everyday words.
            'counts' => $this->counts($r['category'], $r['word']),
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
            $file = str_contains($key, '.') ? 'packs/' . str_replace('.', '/', $key) . '.txt' : "categories/$key.txt";
            $out .= $key === 'dictionary'
                ? "\n## Not in the dictionary (extra-words.txt)\n"
                : "\n## $key: {$reports[0]['categoryLabel']} ($file)\n";
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

    /**
     * Closes every open report: the ones the files now cover show as accepted, the rest as
     * reviewed. Words accepted the old way have been in the download too, so they're done with:
     * whatever the files don't have by now was decided against.
     */
    public function markReviewed(): int
    {
        $stmt = $this->db->prepare('UPDATE reports SET reviewed_at = ? WHERE reviewed_at IS NULL');
        $stmt->execute([$this->now()]);
        try {
            $this->db->exec('DELETE FROM word_decisions');
        } catch (PDOException) {
            // A database from after that table was retired.
        }
        return $stmt->rowCount();
    }

    /**
     * Accounts matching a name or email (part of either), or with no search, every account on
     * a paid plan. Newest first, at most 50. Without $byEmail only names are searched, for
     * helpers who may not see emails.
     */
    public function accounts(string $search = '', bool $byEmail = true): array
    {
        $search = trim($search);
        if ($search === '') {
            $stmt = $this->db->query("SELECT id, name, email, plan FROM users WHERE plan <> 'free' ORDER BY id DESC LIMIT 50");
        } else {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $stmt = $this->db->prepare('SELECT id, name, email, plan FROM users WHERE name LIKE ?' . ($byEmail ? ' OR email LIKE ?' : '') . ' ORDER BY id DESC LIMIT 50');
            $stmt->execute($byEmail ? [$like, $like] : [$like]);
        }
        return array_map(fn ($u) => [
            'id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'plan' => $u['plan'],
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

    /** A helper's permissions, or null if the account isn't a helper. */
    public static function helperPermissions(PDO $db, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT permissions FROM admin_helpers WHERE user_id = ?');
        $stmt->execute([$userId]);
        $permissions = $stmt->fetchColumn();
        return $permissions === false ? null : self::validPermissions(explode(',', (string) $permissions));
    }

    /** The helpers, in the order they were added, with their names, emails and permissions. */
    public function helpers(): array
    {
        $rows = $this->db->query('SELECT h.user_id, h.permissions, h.added_at, u.name, u.email
            FROM admin_helpers h JOIN users u ON u.id = h.user_id ORDER BY h.added_at, h.user_id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn ($r) => [
            'id' => (int) $r['user_id'], 'name' => $r['name'], 'email' => $r['email'], 'addedAt' => (int) $r['added_at'],
            'permissions' => self::validPermissions(explode(',', $r['permissions'])),
        ], $rows);
    }

    /** Makes an account a helper, with NEW_HELPER's permissions; returns its name. */
    public function addHelper(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $name = $stmt->fetchColumn() ?: throw new GameError(404, 'No such account');
        $this->db->prepare('INSERT IGNORE INTO admin_helpers (user_id, permissions, added_at) VALUES (?, ?, ?)')
            ->execute([$userId, implode(',', self::NEW_HELPER), $this->now()]);
        return $name;
    }

    /** @param string[] $permissions keys of PERMISSIONS; others are ignored */
    public function setHelperPermissions(int $userId, array $permissions): void
    {
        $this->db->prepare('UPDATE admin_helpers SET permissions = ? WHERE user_id = ?')
            ->execute([implode(',', self::validPermissions($permissions)), $userId]);
    }

    public function removeHelper(int $userId): void
    {
        $this->db->prepare('DELETE FROM admin_helpers WHERE user_id = ?')->execute([$userId]);
    }

    /** @return string[] the known permissions among these, in PERMISSIONS' order */
    private static function validPermissions(array $permissions): array
    {
        return array_values(array_intersect(array_keys(self::PERMISSIONS), $permissions));
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
        if ($category === self::DICTIONARY_ONLY) return 'Dictionary only';
        $dictionary = $this->dictionaryFor($category);
        return $dictionary->hasCategory($category) ? $dictionary->categoryLabel($category) : $category;
    }

    /** The dictionary a category belongs to: everyday, or a curated pack's ("science.element"). */
    private function dictionaryFor(string $category): Dictionary
    {
        if (!str_contains($category, '.') || !$this->packs) return $this->dictionary;
        return $this->packs->dictionary(strstr($category, '.', true), 'only', $this->dictionary);
    }

    /** Whether a reported word now counts for its category. */
    private function counts(string $category, string $word): bool
    {
        if ($category === self::DICTIONARY_ONLY) return $this->dictionary->has($word);
        $dictionary = $this->dictionaryFor($category);
        return $dictionary->hasCategory($category) && $dictionary->inCategory($category, $word);
    }
}
