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
    public const SPELLINGS = ['both', 'uk', 'us'];
    private const NICKNAME_PATTERN = '/^[\p{L}\p{N}_ -]{1,20}$/u';
    /** Points lost for a guess that doesn't fit the prompt or has already been said. */
    public const PENALTY = 2;
    /** Most guesses a player can make per minute, right or wrong. Stops scripts pasting word lists. */
    public const GUESSES_PER_MINUTE = 20;

    /** @var array<string, array<string, true>> worldId => burned words, cached for this request */
    private array $burnedCache = [];
    /** @var array<string, array<string, true>> worldId => spellings that world doesn't accept */
    private array $wrongSpellingsCache = [];
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly Dictionary $dictionary,
        ?callable $clock = null,
        private readonly int $promptMin = 10,
        private readonly int $promptMax = 80,
        private readonly ?StateCache $stateCache = null,
        /** After this long without a guess, a room player shows as away and their seat is free. */
        public readonly int $awayAfterMs = 7 * 86_400_000,
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
        // Databases from before accounts: link players and worlds to users.
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'user_id'")->fetch()) {
            $db->exec('ALTER TABLE players ADD COLUMN user_id INT UNSIGNED NULL AFTER world_id, ADD UNIQUE KEY uniq_user (world_id, user_id)');
        }
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'owner_user_id'")->fetch()) {
            $db->exec('ALTER TABLE worlds ADD COLUMN owner_user_id INT UNSIGNED NULL');
        }
        // Databases from before UK/US spelling choices.
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'spelling'")->fetch()) {
            $db->exec("ALTER TABLE worlds ADD COLUMN spelling VARCHAR(4) CHARACTER SET ascii NOT NULL DEFAULT 'both' AFTER prompt_duration_ms");
        }
        // Goes up whenever a world's shared state changes, so cached copies know they're out of date.
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'version'")->fetch()) {
            $db->exec('ALTER TABLE worlds ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0');
        }
        // Kinds of world (private, the big world's rounds, rooms) and how they end.
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'kind'")->fetch()) {
            $db->exec("ALTER TABLE worlds
                ADD COLUMN kind VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'private' AFTER name,
                ADD COLUMN prompt_min SMALLINT UNSIGNED NULL,
                ADD COLUMN prompt_max SMALLINT UNSIGNED NULL,
                ADD COLUMN ends_at BIGINT NULL,
                ADD COLUMN ended_at BIGINT NULL,
                ADD KEY idx_kind (kind, ended_at)");
            // The old public world becomes the big world's first round, finished when the next starts.
            $db->exec("UPDATE worlds SET kind = 'public' WHERE id = 'public'");
        }
        // When each player last guessed, so rooms can free the seats of players who've stopped.
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'last_active_at'")->fetch()) {
            // Also an index for finding an account's players across worlds ("Back to your room", My games).
            $db->exec('ALTER TABLE players ADD COLUMN last_active_at BIGINT NOT NULL DEFAULT 0,
                ADD KEY idx_active (world_id, last_active_at), ADD KEY idx_user_id (user_id)');
            $db->exec('UPDATE players p SET last_active_at = GREATEST(created_at,
                COALESCE((SELECT MAX(burned_at) FROM burns WHERE player_id = p.id), 0),
                COALESCE((SELECT MAX(missed_at) FROM misses WHERE player_id = p.id), 0))');
        }
        // Running totals for the overall leaderboard, so polls don't re-add every word and miss.
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'score'")->fetch()) {
            $db->exec('ALTER TABLE players
                ADD COLUMN score INT NOT NULL DEFAULT 0,
                ADD COLUMN words INT UNSIGNED NOT NULL DEFAULT 0,
                ADD KEY idx_score (world_id, score)');
            self::recountScores($db);
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

    /**
     * Recalculates every player's running totals from the words and misses tables. Run
     * automatically when the totals are added; run it again after editing those tables by hand.
     */
    public static function recountScores(PDO $db, ?string $worldId = null): void
    {
        $db->prepare('
            UPDATE players p SET
              score = (SELECT COALESCE(SUM(points), 0) FROM burns WHERE player_id = p.id)
                    + (SELECT COALESCE(SUM(points), 0) FROM misses WHERE player_id = p.id),
              words = (SELECT COUNT(*) FROM burns WHERE player_id = p.id AND word = played_word)
            WHERE ? IS NULL OR p.world_id = ?')
            ->execute([$worldId, $worldId]);
        $db->prepare('UPDATE worlds SET version = version + 1 WHERE ? IS NULL OR id = ?')->execute([$worldId, $worldId]);
    }

    public function dictionarySize(): int
    {
        return $this->dictionary->size();
    }

    /** Marks the world's shared state as changed, for the state cache. */
    private function changed(string $worldId): void
    {
        $this->db->prepare('UPDATE worlds SET version = version + 1 WHERE id = ?')->execute([$worldId]);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    // --- Worlds and players ---

    /**
     * @param array{kind?: string, endsAt?: int, promptMin?: int, promptMax?: int} $options
     *   kind: 'private' (default), 'public' (a round of the big world, ending at endsAt) or 'room';
     *   promptMin/promptMax: how many answers each prompt should have.
     */
    public function createWorld(string $name, ?string $id = null, int $promptDurationMs = self::DEFAULT_PROMPT_DURATION_MS, string $spelling = 'both', ?int $ownerUserId = null, array $options = []): array
    {
        $id ??= bin2hex(random_bytes(4));
        $name = mb_substr(trim($name), 0, 40) ?: 'Unnamed world';
        if (!in_array($spelling, self::SPELLINGS, true)) throw new GameError(400, 'Spelling must be uk, us or both');
        $this->db->prepare('
            INSERT INTO worlds (id, name, kind, prompt_duration_ms, spelling, owner_user_id, prompt_min, prompt_max, ends_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $id, $name, $options['kind'] ?? 'private', $promptDurationMs, $spelling, $ownerUserId,
                $options['promptMin'] ?? null, $options['promptMax'] ?? null, $options['endsAt'] ?? null, $this->now(),
            ]);
        $this->currentPrompt($id);
        return $this->world($id);
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
        if ($this->dictionary->isOffensiveName($name)) throw new GameError(400, 'Please choose a different nickname');
        $token = bin2hex(random_bytes(24));
        try {
            $this->db->prepare('INSERT INTO players (world_id, nickname, token, created_at, last_active_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$worldId, $name, $token, $this->now(), $this->now()]);
        } catch (PDOException $e) {
            // The nickname column is case-insensitive, so "robin" clashes with "Robin".
            if ($e->getCode() === '23000') throw new GameError(409, 'That nickname is taken in this world');
            throw $e;
        }
        return ['playerId' => (int) $this->db->lastInsertId(), 'nickname' => $name, 'token' => $token];
    }

    /** The account's player in this world, or null if they haven't played there yet. */
    public function playerForUser(string $worldId, int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM players WHERE world_id = ? AND user_id = ?');
        $stmt->execute([$worldId, $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The account's player in this world, joining it with the account's name first if needed.
     * If someone from before accounts already has that name here, a number is added ("Ann 2").
     */
    public function joinAsUser(string $worldId, array $user): array
    {
        if ($player = $this->playerForUser($worldId, (int) $user['id'])) return $player;
        $this->world($worldId);
        for ($n = 1; $n <= 50; $n++) {
            $suffix = $n === 1 ? '' : " $n";
            $name = mb_substr($user['name'], 0, 20 - mb_strlen($suffix)) . $suffix;
            try {
                $this->db->prepare('INSERT INTO players (world_id, user_id, nickname, token, created_at, last_active_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$worldId, $user['id'], $name, bin2hex(random_bytes(24)), $this->now(), $this->now()]);
                return $this->playerForUser($worldId, (int) $user['id']);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                if ($player = $this->playerForUser($worldId, (int) $user['id'])) return $player; // Joined in another request.
            }
        }
        throw new GameError(409, 'Could not join this world');
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

    /** @return array<string, true> spellings this world doesn't accept */
    private function wrongSpellings(string $worldId): array
    {
        return $this->wrongSpellingsCache[$worldId] ??= $this->dictionary->wrongSpellings($this->world($worldId)['spelling'] ?? 'both');
    }

    /** Words that can't be answers in this world: already said, or spelled the other side's way. */
    private function unavailable(string $worldId): array
    {
        return $this->burned($worldId) + $this->wrongSpellings($worldId);
    }

    public function lookup(string $worldId, string $rawWord): array
    {
        $this->world($worldId);
        $word = strtolower(trim($rawWord));
        $stmt = $this->db->prepare('
            SELECT b.played_word, b.burned_at, b.player_id, p.nickname, pr.spec, pr.label, pr.type, pr.letters
            FROM burns b JOIN players p ON p.id = b.player_id JOIN prompts pr ON pr.id = b.prompt_id
            WHERE b.world_id = ? AND b.word = ?');
        $stmt->execute([$worldId, $word]);
        $row = $stmt->fetch();
        return [
            'word' => $word,
            'inDictionary' => $this->dictionary->has($word),
            'burned' => $row ? [
                'by' => $row['nickname'],
                'playerId' => (int) $row['player_id'],
                'at' => (int) $row['burned_at'],
                'playedWord' => $row['played_word'],
                'prompt' => $row['label'] ?? Prompts::describe(self::specOf($row), $this->dictionary),
            ] : null,
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
        $prompt['remaining'] = Prompts::countUnsaid($prompt['spec'], $this->dictionary, $this->unavailable($worldId));
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
        if ($this->world($worldId)['ended_at'] ?? null) return null;
        return $this->livePrompt($worldId) ?? $this->withWorldLock($worldId, function () use ($worldId) {
            if ($prompt = $this->livePrompt($worldId)) return $prompt; // Another request rotated it first.
            $world = $this->world($worldId);
            if ($world['ended_at'] ?? null) return null; // Another request finished it first.
            $this->db->prepare('UPDATE prompts SET ended_at = ? WHERE world_id = ? AND ended_at IS NULL')->execute([$this->now(), $worldId]);
            $this->changed($worldId);
            $endsAt = isset($world['ends_at']) ? (int) $world['ends_at'] : null;
            if ($endsAt !== null && $this->now() >= $endsAt) {
                $this->finishWorld($worldId); // The round's time is up.
                return null;
            }
            $stmt = $this->db->prepare('SELECT spec, type, letters FROM prompts WHERE world_id = ?');
            $stmt->execute([$worldId]);
            $used = [];
            foreach ($stmt->fetchAll() as $row) $used[Prompts::key(self::specOf($row))] = true;
            $next = Prompts::pick(
                $this->dictionary, $this->unavailable($worldId), $used,
                (int) ($world['prompt_min'] ?? $this->promptMin), (int) ($world['prompt_max'] ?? $this->promptMax),
            );
            if (!$next) {
                $this->finishWorld($worldId); // Out of words.
                return null;
            }
            $start = $this->now();
            $this->db->prepare('
                INSERT INTO prompts (world_id, spec, label, available_at_start, started_at, ends_at)
                VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $worldId, Prompts::key($next['spec']), Prompts::describe($next['spec'], $this->dictionary),
                    // A round's last prompt stops when the round does.
                    $next['available'], $start, min($start + (int) $world['prompt_duration_ms'], $endsAt ?? PHP_INT_MAX),
                ]);
            $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ?');
            $stmt->execute([$this->db->lastInsertId()]);
            return ['spec' => $next['spec'], 'remaining' => $next['available']] + $stmt->fetch();
        });
    }

    /**
     * Ends a world and records its final standings in results, for "My games". Everyone who
     * said a word or lost points is placed; players on the same score share a place.
     */
    public function finishWorld(string $worldId): void
    {
        $this->withWorldLock($worldId, function () use ($worldId) {
            $stmt = $this->db->prepare('SELECT ended_at FROM worlds WHERE id = ?');
            $stmt->execute([$worldId]);
            if ($stmt->fetchColumn()) return; // Already finished.
            $now = $this->now();

            $stmt = $this->db->prepare('
                SELECT id, user_id, nickname, score, words FROM players
                WHERE world_id = ? AND (words > 0 OR score <> 0)
                ORDER BY score DESC, words DESC, nickname');
            $stmt->execute([$worldId]);
            $players = $stmt->fetchAll();

            // Each player's best word: the most points for one word.
            $best = [];
            $stmt = $this->db->prepare('SELECT player_id, played_word, points FROM burns WHERE world_id = ? AND word = played_word');
            $stmt->execute([$worldId]);
            foreach ($stmt->fetchAll() as $b) {
                if (!isset($best[$b['player_id']]) || $b['points'] > $best[$b['player_id']]['points']) $best[$b['player_id']] = $b;
            }

            $insert = $this->db->prepare('
                INSERT INTO results (world_id, user_id, nickname, place, players, score, words, best_word, best_points, ended_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $place = 0;
            foreach ($players as $i => $p) {
                if ($i === 0 || $p['score'] !== $players[$i - 1]['score']) $place = $i + 1;
                $b = $best[$p['id']] ?? null;
                $insert->execute([
                    $worldId, $p['user_id'], $p['nickname'], $place, count($players), $p['score'], $p['words'],
                    $b['played_word'] ?? null, $b['points'] ?? null, $now,
                ]);
            }
            $this->db->prepare('UPDATE worlds SET ended_at = ? WHERE id = ?')->execute([$now, $worldId]);
            $this->db->prepare('UPDATE prompts SET ended_at = ? WHERE world_id = ? AND ended_at IS NULL')->execute([$now, $worldId]);
            $this->changed($worldId);
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
            $prompt = $this->currentPrompt($worldId) ?? throw new GameError(410, $this->endedMessage($this->world($worldId)));

            // Typos aren't penalised, but a word that doesn't fit or was already said costs points,
            // so pasting a list of answers from a word-finder site loses more than it gains.
            if (!$this->dictionary->has($word)) return $this->miss($player, $prompt, $word, 'not-a-word', 0);
            // The other side's spelling ("color" in a UK world): free, with the right spelling.
            $wrong = $this->wrongSpellings($worldId);
            if (isset($wrong[$word])) {
                return $this->miss($player, $prompt, $word, 'wrong-spelling', 0) + [
                    'spelling' => $this->world($worldId)['spelling'],
                    'suggestions' => array_values(array_filter($this->dictionary->otherSpellings($word), fn ($w) => !isset($wrong[$w]))),
                ];
            }
            // Already said comes before checking the prompt, so nobody is told to report a word
            // that's used up anyway. Repeating your own word is an honest slip, so it's free.
            $burned = $this->burned($worldId);
            if (isset($burned[$word])) {
                $said = $this->lookup($worldId, $word)['burned'];
                $mine = $said && $said['playerId'] === (int) $player['id'];
                return $this->miss($player, $prompt, $word, 'already-burned', $mine ? 0 : self::PENALTY)
                    + ['burned' => $said, 'byYou' => $mine];
            }
            if (!Prompts::matches($prompt['spec'], $this->dictionary, $word)) {
                // Right letters but not on our list for the meaning: our lists have gaps, so no
                // penalty, and the player can report the word.
                if (isset($prompt['spec']['cat']) && Prompts::matches(['cat' => null] + $prompt['spec'], $this->dictionary, $word)) {
                    return $this->miss($player, $prompt, $word, 'not-in-category', 0)
                        + ['category' => $this->dictionary->categoryLabel($prompt['spec']['cat'])];
                }
                return $this->miss($player, $prompt, $word, 'doesnt-fit', self::PENALTY);
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
            $this->db->prepare('UPDATE players SET score = score + ?, words = words + 1, last_active_at = ? WHERE id = ?')
                ->execute([$points, $at, $player['id']]);
            $this->changed($worldId);
            return [
                'ok' => true,
                'word' => $word,
                'points' => $points,
                'alsoBurned' => array_values(array_filter($family, fn ($w) => $w !== $word)),
                'multipliers' => $mult,
            ];
        });
    }

    /** Why a finished world can't be played any more: for someone trying to play, or looking at it. */
    public function endedMessage(array $world, bool $playing = true): string
    {
        return match ($world['kind'] ?? 'private') {
            'public' => $playing ? 'This round has finished. Reload the page for the new one.' : 'This round of the big world has finished.',
            'room' => 'This room has run out of words. Join a new room from the home page.',
            default => 'This world has run out of words',
        };
    }

    private function miss(array $player, array $prompt, string $word, string $reason, int $penalty): array
    {
        $this->db->prepare('
            INSERT INTO misses (world_id, player_id, prompt_id, word, reason, points, missed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$player['world_id'], $player['id'], $prompt['id'], $word, $reason, -$penalty, $this->now()]);
        $this->db->prepare('UPDATE players SET score = score - ?, last_active_at = ? WHERE id = ?')
            ->execute([$penalty, $this->now(), $player['id']]);
        // Penalties show on the leaderboards; free misses are private.
        if ($penalty) $this->changed($player['world_id']);
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
        // Report status: the player's report of this word for this prompt, and the admin's decision.
        $stmt = $this->db->prepare("
            SELECT m.word, m.reason, m.points, m.missed_at, m.prompt_id, p.spec, p.label, p.type, p.letters,
              m.world_id, r.id AS report_id, d.decision
            FROM misses m
            JOIN prompts p ON p.id = m.prompt_id
            LEFT JOIN reports r ON r.player_id = m.player_id AND r.prompt_id = m.prompt_id AND r.word = m.word
            LEFT JOIN word_decisions d ON d.category = r.category AND d.word = r.word
            WHERE m.player_id = ?
            ORDER BY m.id DESC LIMIT $limit");
        $stmt->execute([$playerId]);
        $rows = $stmt->fetchAll();

        // Whether each word has been used up since (by anyone, for any prompt). Looked up
        // separately: joining misses to burns can't use the index, as their word columns differ.
        $saidSince = [];
        if ($rows) {
            $words = array_values(array_unique(array_column($rows, 'word')));
            $stmt = $this->db->prepare('
                SELECT b.word, b.player_id AS said_by_id, b.prompt_id AS said_prompt_id, bp.nickname AS said_by,
                  bpr.spec AS said_spec, bpr.label AS said_label, bpr.type AS said_type, bpr.letters AS said_letters
                FROM burns b JOIN players bp ON bp.id = b.player_id JOIN prompts bpr ON bpr.id = b.prompt_id
                WHERE b.world_id = ? AND b.word IN (' . implode(',', array_fill(0, count($words), '?')) . ')');
            $stmt->execute([$rows[0]['world_id'], ...$words]);
            foreach ($stmt->fetchAll() as $b) $saidSince[$b['word']] = $b;
        }
        return array_map(function ($r) use ($saidSince, $playerId) {
            $b = $saidSince[$r['word']] ?? null;
            return [
                'word' => $r['word'],
                'reason' => $r['reason'],
                'penalty' => -(int) $r['points'],
                'prompt' => $r['label'] ?? Prompts::describe(self::specOf($r), $this->dictionary),
                'promptId' => (int) $r['prompt_id'],
                'at' => (int) $r['missed_at'],
                // null (not reported), 'reported' (waiting), 'accepted' or 'dismissed'.
                'reportStatus' => $r['report_id'] ? ($r['decision'] ?? 'reported') : null,
                // Who has used the word up, and for which prompt; null while it's still unsaid.
                'said' => $b === null ? null : [
                    'by' => $b['said_by'],
                    'byYou' => (int) $b['said_by_id'] === $playerId,
                    'promptId' => (int) $b['said_prompt_id'],
                    'prompt' => $b['said_label'] ?? Prompts::describe(self::specOf([
                        'spec' => $b['said_spec'], 'type' => $b['said_type'], 'letters' => $b['said_letters'],
                    ]), $this->dictionary),
                ],
            ];
            }, $rows);
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

    /**
     * Everything a page needs to show a world. The shared part is the same for every viewer,
     * so it comes from the state cache when there is one; only the player's own part is
     * worked out on each request.
     */
    public function state(string $worldId, ?string $token): array
    {
        $world = $this->world($worldId);
        $shared = $this->stateCache
            ? $this->stateCache->get($worldId, (int) ($world['version'] ?? 0), fn () => $this->sharedState($world))
            : $this->sharedState($world);

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
            'me' => $me,
            // Only the player's own misses: other players never see them.
            'myMisses' => $myMisses,
        ] + $shared + ['now' => $this->now()];
    }

    /** The part of a world's state that's the same for everyone watching it. */
    private function sharedState(array $world): array
    {
        $worldId = $world['id'];
        $prompt = $this->currentPrompt($worldId);
        if (!$prompt) $world = $this->world($worldId); // It may have just finished.
        $ended = (bool) ($world['ended_at'] ?? null);
        $mult = $this->multipliers($worldId, $prompt);
        $promptId = $prompt['id'] ?? -1;

        // Scores include penalties for wrong guesses. Kept as running totals on each player.
        $stmt = $this->db->prepare('
            SELECT nickname, score, words, last_active_at FROM players WHERE world_id = ?
            ORDER BY score DESC, words DESC, nickname LIMIT 50');
        $stmt->execute([$worldId]);
        // In rooms, players who've stopped playing show as away: their seat is free for someone new.
        $awaySince = ($world['kind'] ?? '') === 'room' && !$world['ended_at'] ? $this->now() - $this->awayAfterMs : null;
        $leaderboard = array_map(fn ($r) => [
            'nickname' => $r['nickname'], 'total' => (int) $r['score'], 'words' => (int) $r['words'],
        ] + ($awaySince !== null && (int) $r['last_active_at'] < $awaySince ? ['away' => true] : []), $stmt->fetchAll());

        // The same, ranked by the current prompt alone, so late arrivals can top something.
        // Only players who have tried a word in this prompt are listed.
        $stmt = $this->db->prepare('
            SELECT p.nickname, COALESCE(b.words, 0) AS words, COALESCE(b.points, 0) + COALESCE(m.points, 0) AS points
            FROM players p
            LEFT JOIN (
              SELECT player_id, COUNT(CASE WHEN word = played_word THEN 1 END) AS words, SUM(points) AS points
              FROM burns WHERE prompt_id = ? GROUP BY player_id
            ) b ON b.player_id = p.id
            LEFT JOIN (
              SELECT player_id, SUM(points) AS points FROM misses WHERE prompt_id = ? GROUP BY player_id
            ) m ON m.player_id = p.id
            WHERE p.world_id = ? AND (b.player_id IS NOT NULL OR m.player_id IS NOT NULL)
            ORDER BY points DESC, words DESC, p.nickname
            LIMIT 50');
        $stmt->execute([$promptId, $promptId, $worldId]);
        $promptBoard = array_map(fn ($r) => [
            'nickname' => $r['nickname'], 'points' => (int) $r['points'], 'words' => (int) $r['words'],
        ], $stmt->fetchAll());

        return [
            'world' => [
                'id' => $world['id'], 'name' => $world['name'], 'spelling' => $world['spelling'] ?? 'both',
                'kind' => $world['kind'] ?? 'private',
                'endsAt' => isset($world['ends_at']) ? (int) $world['ends_at'] : null,
                // Set once the world is over: out of words, or the round's time is up.
                'ended' => $ended,
                'endedMessage' => $ended ? $this->endedMessage($world, playing: false) : null,
            ],
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
            'said' => $this->saidByPrompt($worldId),
            'leaderboard' => $leaderboard,
            'promptBoard' => $promptBoard,
        ];
    }
}
