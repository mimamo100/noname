<?php
declare(strict_types=1);

final class GameError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

final class Game
{
    public const DEFAULT_PROMPT_DURATION_MS = 24 * 60 * 60 * 1000;
    public const PUBLIC_WORLD_ID = 'public';
    private const NICKNAME_PATTERN = '/^[\p{L}\p{N}_ -]{1,20}$/u';
    /** Points lost for a guess that doesn't fit the prompt or has already been said. */
    public const PENALTY = 2;
    /** Most guesses a player can make per minute, right or wrong. Stops scripts pasting word lists. */
    public const GUESSES_PER_MINUTE = 20;

    /** @var array<string, array<string, true>> worldId => burned words, cached for this request */
    private array $burnedCache = [];
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly Dictionary $dictionary,
        ?callable $clock = null,
        private readonly int $promptMin = 10,
        private readonly int $promptMax = 80,
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    /** Creates any missing tables and upgrades older databases. Safe to run repeatedly. */
    public static function installSchema(PDO $db): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(dirname(__DIR__) . '/schema.sql')); // Comments may contain ";".
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $db->exec($statement);
        }
        // Databases from before the new prompt types: add the spec columns.
        if (!$db->query("SHOW COLUMNS FROM prompts LIKE 'spec'")->fetch()) {
            $db->exec("ALTER TABLE prompts
                ADD COLUMN spec TEXT NULL AFTER world_id,
                ADD COLUMN label VARCHAR(200) NULL AFTER spec,
                MODIFY type VARCHAR(10) CHARACTER SET ascii NULL,
                MODIFY letters VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NULL");
        }
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    // --- Worlds and players ---

    public function createWorld(string $name, ?string $id = null, int $promptDurationMs = self::DEFAULT_PROMPT_DURATION_MS): array
    {
        $id ??= bin2hex(random_bytes(4));
        $name = mb_substr(trim($name), 0, 40) ?: 'Unnamed world';
        $this->db->prepare('INSERT INTO worlds (id, name, prompt_duration_ms, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $name, $promptDurationMs, $this->now()]);
        $this->currentPrompt($id);
        return $this->world($id);
    }

    public function ensureWorld(string $id, string $name): array
    {
        $stmt = $this->db->prepare('SELECT 1 FROM worlds WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) return $this->world($id);
        try {
            return $this->createWorld($name, $id);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            return $this->world($id); // Another request created it first.
        }
    }

    public function world(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM worlds WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new GameError(404, 'World not found');
    }

    public function join(string $worldId, string $nickname): array
    {
        $this->world($worldId);
        $name = trim($nickname);
        if (!preg_match(self::NICKNAME_PATTERN, $name)) {
            throw new GameError(400, 'Nicknames are 1–20 letters, numbers, spaces, - or _');
        }
        if ($this->isOffensive($name)) throw new GameError(400, 'Please choose a different nickname');
        $token = bin2hex(random_bytes(24));
        try {
            $this->db->prepare('INSERT INTO players (world_id, nickname, token, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$worldId, $name, $token, $this->now()]);
        } catch (PDOException $e) {
            // The nickname column is case-insensitive, so "robin" clashes with "Robin".
            if ($e->getCode() === '23000') throw new GameError(409, 'That nickname is taken in this world');
            throw $e;
        }
        return ['playerId' => (int) $this->db->lastInsertId(), 'nickname' => $name, 'token' => $token];
    }

    /** Whether any word in the name, or the whole name squashed together, is on the blocklist. */
    private function isOffensive(string $name): bool
    {
        $lower = mb_strtolower($name);
        $parts = preg_split('/[^a-z]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $parts[] = preg_replace('/[^a-z]/', '', $lower);
        foreach ($parts as $part) {
            if ($this->dictionary->isBlocked($part)) return true;
        }
        return false;
    }

    private function playerByToken(string $worldId, ?string $token): array
    {
        $stmt = $this->db->prepare('SELECT * FROM players WHERE world_id = ? AND token = ?');
        $stmt->execute([$worldId, (string) $token]);
        return $stmt->fetch() ?: throw new GameError(401, 'Join this world first');
    }

    // --- Burned words ---

    /** @return array<string, true> */
    private function burned(string $worldId): array
    {
        if (!isset($this->burnedCache[$worldId])) {
            $stmt = $this->db->prepare('SELECT word FROM burns WHERE world_id = ?');
            $stmt->execute([$worldId]);
            $this->burnedCache[$worldId] = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        }
        return $this->burnedCache[$worldId];
    }

    public function lookup(string $worldId, string $rawWord): array
    {
        $this->world($worldId);
        $word = strtolower(trim($rawWord));
        $stmt = $this->db->prepare('
            SELECT b.played_word, b.burned_at, p.nickname
            FROM burns b JOIN players p ON p.id = b.player_id
            WHERE b.world_id = ? AND b.word = ?');
        $stmt->execute([$worldId, $word]);
        $row = $stmt->fetch();
        return [
            'word' => $word,
            'inDictionary' => $this->dictionary->has($word),
            'burned' => $row ? ['by' => $row['nickname'], 'at' => (int) $row['burned_at'], 'playedWord' => $row['played_word']] : null,
        ];
    }

    // --- Prompts ---

    /** Runs $fn while holding a lock on the world, so plays and prompt changes don't overlap. */
    private function withWorldLock(string $worldId, callable $fn): mixed
    {
        if ($this->db->inTransaction()) return $fn();
        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM worlds WHERE id = ? FOR UPDATE')->execute([$worldId]);
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** The active prompt with its remaining count, or null if it has expired or run dry. */
    private function livePrompt(string $worldId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM prompts WHERE world_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$worldId]);
        $prompt = $stmt->fetch();
        if (!$prompt) return null;
        $prompt['spec'] = self::specOf($prompt);
        $prompt['remaining'] = Prompts::countUnsaid($prompt['spec'], $this->dictionary, $this->burned($worldId));
        return $prompt['remaining'] > 0 && $this->now() < (int) $prompt['ends_at'] ? $prompt : null;
    }

    /** A stored prompt's spec. Prompts from before the spec column were simple letter patterns. */
    public static function specOf(array $row): array
    {
        if (!empty($row['spec'])) return json_decode($row['spec'], true);
        return ['cat' => null, 'rules' => [[$row['type'], $row['letters']]]];
    }

    /** Returns the active prompt, rotating it first if it has expired or run dry. Null when the world has no words left. */
    public function currentPrompt(string $worldId): ?array
    {
        return $this->livePrompt($worldId) ?? $this->withWorldLock($worldId, function () use ($worldId) {
            if ($prompt = $this->livePrompt($worldId)) return $prompt; // Another request rotated it first.
            $world = $this->world($worldId);
            $this->db->prepare('UPDATE prompts SET ended_at = ? WHERE world_id = ? AND ended_at IS NULL')->execute([$this->now(), $worldId]);
            $stmt = $this->db->prepare('SELECT spec, type, letters FROM prompts WHERE world_id = ?');
            $stmt->execute([$worldId]);
            $used = [];
            foreach ($stmt->fetchAll() as $row) $used[Prompts::key(self::specOf($row))] = true;
            $next = Prompts::pick($this->dictionary, $this->burned($worldId), $used, $this->promptMin, $this->promptMax);
            if (!$next) return null;
            $start = $this->now();
            $this->db->prepare('
                INSERT INTO prompts (world_id, spec, label, available_at_start, started_at, ends_at)
                VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $worldId, Prompts::key($next['spec']), Prompts::describe($next['spec'], $this->dictionary),
                    $next['available'], $start, $start + (int) $world['prompt_duration_ms'],
                ]);
            $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ?');
            $stmt->execute([$this->db->lastInsertId()]);
            return ['spec' => $next['spec'], 'remaining' => $next['available']] + $stmt->fetch();
        });
    }

    private function multipliers(string $worldId, ?array $prompt): array
    {
        return [
            'world' => Scoring::worldMultiplier(count($this->burned($worldId)), $this->dictionary->size()),
            'prompt' => $prompt ? Scoring::promptBonus((int) $prompt['remaining'], (int) $prompt['available_at_start']) : 1.0,
        ];
    }

    // --- Playing ---

    public function play(string $worldId, ?string $token, string $rawWord): array
    {
        $player = $this->playerByToken($worldId, $token);
        $word = mb_substr(strtolower(trim($rawWord)), 0, 40);
        $this->checkRateLimit($player);
        return $this->withWorldLock($worldId, function () use ($worldId, $player, $word) {
            unset($this->burnedCache[$worldId]); // Re-read under the lock.
            $prompt = $this->currentPrompt($worldId) ?? throw new GameError(410, 'This world has run out of words');

            // Typos aren't penalised, but a word that doesn't fit or was already said costs points,
            // so pasting a list of answers from a word-finder site loses more than it gains.
            if (!$this->dictionary->has($word)) return $this->miss($player, $prompt, $word, 'not-a-word', 0);
            if (!Prompts::matches($prompt['spec'], $this->dictionary, $word)) {
                // Right letters but not on our list for the meaning: our lists have gaps, so no
                // penalty, and the player can report the word.
                if (isset($prompt['spec']['cat']) && Prompts::matches(['cat' => null] + $prompt['spec'], $this->dictionary, $word)) {
                    return $this->miss($player, $prompt, $word, 'not-in-category', 0)
                        + ['category' => $this->dictionary->categoryLabel($prompt['spec']['cat'])];
                }
                return $this->miss($player, $prompt, $word, 'doesnt-fit', self::PENALTY);
            }
            $burned = $this->burned($worldId);
            if (isset($burned[$word])) {
                return $this->miss($player, $prompt, $word, 'already-burned', self::PENALTY)
                    + ['burned' => $this->lookup($worldId, $word)['burned']];
            }

            $mult = $this->multipliers($worldId, $prompt);
            // A plural scores as its singular, so adding an S earns nothing extra.
            $singular = $this->dictionary->singular($word);
            $points = Scoring::scoreWord($singular, $this->dictionary->rarity($singular), $mult['world'], $mult['prompt']);
            $family = array_values(array_filter($this->dictionary->family($word), fn ($w) => !isset($burned[$w])));
            $insert = $this->db->prepare('
                INSERT INTO burns (world_id, word, played_word, player_id, prompt_id, points, burned_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            $at = $this->now();
            foreach ($family as $w) {
                $insert->execute([$worldId, $w, $word, $player['id'], $prompt['id'], $w === $word ? $points : 0, $at]);
                $this->burnedCache[$worldId][$w] = true;
            }
            return [
                'ok' => true,
                'word' => $word,
                'points' => $points,
                'alsoBurned' => array_values(array_filter($family, fn ($w) => $w !== $word)),
                'multipliers' => $mult,
            ];
        });
    }

    private function miss(array $player, array $prompt, string $word, string $reason, int $penalty): array
    {
        $this->db->prepare('
            INSERT INTO misses (world_id, player_id, prompt_id, word, reason, points, missed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$player['world_id'], $player['id'], $prompt['id'], $word, $reason, -$penalty, $this->now()]);
        return ['ok' => false, 'word' => $word, 'reason' => $reason, 'penalty' => $penalty];
    }

    /**
     * Records a player's report that a word should count: for the current prompt's meaning,
     * or as a real word missing from the dictionary. The admin page reviews reports.
     */
    public function report(string $worldId, ?string $token, string $rawWord): array
    {
        $player = $this->playerByToken($worldId, $token);
        $word = strtolower(trim($rawWord));
        if (!preg_match('/^[a-z]{3,30}$/', $word)) throw new GameError(400, 'Only words of 3–30 letters can be reported');
        $prompt = $this->currentPrompt($worldId) ?? throw new GameError(410, 'This world has run out of words');
        $category = $prompt['spec']['cat'] ?? Admin::DICTIONARY_ONLY;
        if ($category === Admin::DICTIONARY_ONLY && $this->dictionary->has($word)) {
            throw new GameError(400, 'That word is already in the dictionary');
        }
        $this->db->prepare('
            INSERT IGNORE INTO reports (world_id, player_id, prompt_id, category, word, reported_at)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$worldId, $player['id'], $prompt['id'], $category, $word, $this->now()]);
        return ['ok' => true];
    }

    /** Adds the words the admin has accepted to this request's dictionary. */
    public function applyWordDecisions(): void
    {
        $rows = $this->db->query("SELECT category, word FROM word_decisions WHERE decision = 'accepted'")->fetchAll();
        foreach ($rows as $row) {
            if ($row['category'] === Admin::DICTIONARY_ONLY) $this->dictionary->addWord($row['word']);
            else $this->dictionary->addToCategory($row['category'], $row['word']);
        }
    }

    /**
     * The words said in the world's latest prompts (the current one first), each with all
     * its words, newest first. Older prompts' words can still be found with lookup().
     */
    private function saidByPrompt(string $worldId, int $prompts = 4): array
    {
        $stmt = $this->db->prepare("SELECT id, spec, label, type, letters FROM prompts WHERE world_id = ? ORDER BY id DESC LIMIT $prompts");
        $stmt->execute([$worldId]);
        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groups[(int) $row['id']] = [
                'promptId' => (int) $row['id'],
                'prompt' => $row['label'] ?? Prompts::describe(self::specOf($row), $this->dictionary),
                'words' => [],
            ];
        }
        if (!$groups) return [];
        $ids = implode(',', array_keys($groups)); // Integers from the database.
        $stmt = $this->db->prepare("
            SELECT b.word, b.points, b.burned_at, b.prompt_id, p.nickname,
              (SELECT COUNT(*) - 1 FROM burns f WHERE f.world_id = b.world_id AND f.played_word = b.word) AS family_count
            FROM burns b JOIN players p ON p.id = b.player_id
            WHERE b.world_id = ? AND b.word = b.played_word AND b.prompt_id IN ($ids)
            ORDER BY b.id DESC");
        $stmt->execute([$worldId]);
        foreach ($stmt->fetchAll() as $r) {
            $groups[(int) $r['prompt_id']]['words'][] = [
                'word' => $r['word'], 'points' => (int) $r['points'], 'by' => $r['nickname'],
                'at' => (int) $r['burned_at'], 'familyCount' => (int) $r['family_count'],
            ];
        }
        return array_values($groups);
    }

    /** A player's most recent wrong guesses, newest first, with the prompt each was for. */
    private function missesOf(int $playerId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT m.word, m.reason, m.points, m.missed_at, m.prompt_id, p.spec, p.label, p.type, p.letters
            FROM misses m JOIN prompts p ON p.id = m.prompt_id
            WHERE m.player_id = ?
            ORDER BY m.id DESC LIMIT $limit");
        $stmt->execute([$playerId]);
        return array_map(fn ($r) => [
            'word' => $r['word'],
            'reason' => $r['reason'],
            'penalty' => -(int) $r['points'],
            'prompt' => $r['label'] ?? Prompts::describe(self::specOf($r), $this->dictionary),
            'promptId' => (int) $r['prompt_id'],
            'at' => (int) $r['missed_at'],
        ], $stmt->fetchAll());
    }

    private function checkRateLimit(array $player): void
    {
        $since = $this->now() - 60_000;
        $stmt = $this->db->prepare('
            SELECT (SELECT COUNT(*) FROM burns WHERE player_id = ? AND word = played_word AND burned_at > ?)
                 + (SELECT COUNT(*) FROM misses WHERE player_id = ? AND missed_at > ?)');
        $stmt->execute([$player['id'], $since, $player['id'], $since]);
        if ((int) $stmt->fetchColumn() >= self::GUESSES_PER_MINUTE) {
            throw new GameError(429, 'Slow down! You can make ' . self::GUESSES_PER_MINUTE . ' guesses a minute.');
        }
    }

    // --- State for the client ---

    public function state(string $worldId, ?string $token): array
    {
        $world = $this->world($worldId);
        $prompt = $this->currentPrompt($worldId);
        $mult = $this->multipliers($worldId, $prompt);

        $said = $this->saidByPrompt($worldId);

        // Totals include penalties for wrong guesses, which are stored as negative points.
        $stmt = $this->db->prepare('
            SELECT p.nickname, s.words,
              s.points + COALESCE(m.points, 0) AS total,
              s.prompt_points + COALESCE(m.prompt_points, 0) AS this_prompt
            FROM players p
            JOIN (
              SELECT p2.id,
                COUNT(CASE WHEN b.word = b.played_word THEN 1 END) AS words,
                COALESCE(SUM(b.points), 0) AS points,
                COALESCE(SUM(CASE WHEN b.prompt_id = ? THEN b.points END), 0) AS prompt_points
              FROM players p2 LEFT JOIN burns b ON b.player_id = p2.id
              WHERE p2.world_id = ?
              GROUP BY p2.id
            ) s ON s.id = p.id
            LEFT JOIN (
              SELECT player_id, SUM(points) AS points, SUM(CASE WHEN prompt_id = ? THEN points ELSE 0 END) AS prompt_points
              FROM misses WHERE world_id = ? GROUP BY player_id
            ) m ON m.player_id = p.id
            ORDER BY total DESC, s.words DESC, p.nickname
            LIMIT 50');
        $promptId = $prompt['id'] ?? -1;
        $stmt->execute([$promptId, $worldId, $promptId, $worldId]);
        $leaderboard = array_map(fn ($r) => [
            'nickname' => $r['nickname'], 'total' => (int) $r['total'],
            'thisPrompt' => (int) $r['this_prompt'], 'words' => (int) $r['words'],
        ], $stmt->fetchAll());

        $me = null;
        $myMisses = [];
        if ($token) {
            $stmt = $this->db->prepare('SELECT id, nickname FROM players WHERE world_id = ? AND token = ?');
            $stmt->execute([$worldId, $token]);
            if ($player = $stmt->fetch()) {
                $me = $player['nickname'];
                $myMisses = $this->missesOf((int) $player['id']);
            }
        }

        return [
            'world' => ['id' => $world['id'], 'name' => $world['name']],
            'me' => $me,
            // Only the player's own misses: other players never see them.
            'myMisses' => $myMisses,
            'prompt' => $prompt ? [
                'id' => (int) $prompt['id'],
                'label' => $prompt['label'] ?? Prompts::describe($prompt['spec'], $this->dictionary),
                'remaining' => (int) $prompt['remaining'],
                'availableAtStart' => (int) $prompt['available_at_start'],
                'endsAt' => (int) $prompt['ends_at'],
            ] : null,
            'multipliers' => $mult,
            'dictionary' => ['size' => $this->dictionary->size(), 'burned' => count($this->burned($worldId))],
            // Every word said in the current prompt and the few before it, newest prompt first.
            'said' => $said,
            'leaderboard' => $leaderboard,
            'now' => $this->now(),
        ];
    }
}
