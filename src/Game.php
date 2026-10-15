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
    /**
     * A prompt ends once this share of its answers has been said, rather than running on with
     * only obscure answers left; the answer that gets it there earns Scoring::CLOSING_BONUS.
     */
    public const END_SHARE = 0.8;
    /** "Already said" by someone else this recently costs nothing: being slow isn't being wrong. */
    public const SAID_GRACE_MS = 60_000;
    /** For this long after a prompt changes, a word sent for the old one is judged against it. */
    public const PROMPT_GRACE_MS = 30_000;
    /**
     * In a world with an end time (a competition, a big world round), no prompt starts this close
     * to the end: the one before runs on to the end instead, and doesn't stop at END_SHARE.
     */
    public const LAST_PROMPT_MS = 5 * 60_000;

    /** @var array<string, array<string, true>> worldId => burned words, cached for this request */
    private array $burnedCache = [];
    /** @var array<string, array<string, true>> worldId => spellings that world doesn't accept */
    private array $wrongSpellingsCache = [];
    /** @var callable(): int */
    private $clock;

    /**
     * How many answers a room's prompts have. A room holds up to 60 players, and a 10-answer
     * prompt went in a minute with six (room playtest, 14 October): bigger prompts last, so a
     * slower player gets a turn.
     */
    public const ROOM_PROMPT_ANSWERS = [40, 200];

    public function __construct(
        private readonly PDO $db,
        private readonly Dictionary $dictionary,
        ?callable $clock = null,
        private readonly int $promptMin = 10,
        private readonly int $promptMax = 80,
        private readonly ?StateCache $stateCache = null,
        /** After this long without a guess, a room player shows as away and their seat is free. */
        public readonly int $awayAfterMs = 7 * 86_400_000,
        /** Counts accounts' days in a row; without it, streaks aren't kept. */
        private readonly ?Streaks $streaks = null,
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
        // Plans (free, host, organiser), and private worlds' invite links and owner controls.
        if (!$db->query("SHOW COLUMNS FROM users LIKE 'plan'")->fetch()) {
            $db->exec("ALTER TABLE users ADD COLUMN plan VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'free'");
        }
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'invite_code'")->fetch()) {
            $db->exec('ALTER TABLE worlds
                ADD COLUMN invite_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                ADD COLUMN closed TINYINT NOT NULL DEFAULT 0,
                ADD COLUMN max_players SMALLINT UNSIGNED NULL,
                ADD KEY idx_owner (owner_user_id, kind, ended_at)');
            // Existing private worlds get a code: their players carry on, newcomers need the new link.
            $update = $db->prepare('UPDATE worlds SET invite_code = ? WHERE id = ?');
            foreach ($db->query("SELECT id FROM worlds WHERE kind = 'private'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $update->execute([self::newInviteCode(), $id]);
            }
        }
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'removed'")->fetch()) {
            $db->exec('ALTER TABLE players ADD COLUMN removed TINYINT NOT NULL DEFAULT 0');
        }
        // Reports are a queue for the word files: reviewed_at is set once the admin has been through them.
        if (!$db->query("SHOW COLUMNS FROM reports LIKE 'reviewed_at'")->fetch()) {
            $db->exec('ALTER TABLE reports ADD COLUMN reviewed_at BIGINT NULL, ADD KEY idx_open (reviewed_at)');
        }
        // Competitions: a start time, a team size limit, players' teams, and team names in results.
        // The kind column was made for 8 letters, and "competition" has 11.
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'starts_at'")->fetch()) {
            $db->exec("ALTER TABLE worlds ADD COLUMN starts_at BIGINT NULL, ADD COLUMN team_size SMALLINT UNSIGNED NULL,
                MODIFY kind VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'private'");
        }
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'team_id'")->fetch()) {
            $db->exec('ALTER TABLE players ADD COLUMN team_id INT UNSIGNED NULL, ADD KEY idx_team (team_id)');
        }
        if (!$db->query("SHOW COLUMNS FROM results LIKE 'team_name'")->fetch()) {
            $db->exec('ALTER TABLE results ADD COLUMN team_name VARCHAR(40) NULL');
        }
        // Word packs: which pack a world plays, and how ('only' its words, or 'mixed' with everyday
        // ones). Pack categories ("science.element") need longer names in reports.
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'pack'")->fetch()) {
            $db->exec('ALTER TABLE worlds ADD COLUMN pack VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                ADD COLUMN pack_mode VARCHAR(8) CHARACTER SET ascii NULL');
            $db->exec('ALTER TABLE reports MODIFY category VARCHAR(64) CHARACTER SET ascii NOT NULL');
        }
        // Whether repeating a word someone else said costs points: free unless a host or organiser
        // chooses otherwise for their world (owner's decision after the room playtest).
        if (!$db->query("SHOW COLUMNS FROM worlds LIKE 'repeat_penalty'")->fetch()) {
            $db->exec('ALTER TABLE worlds ADD COLUMN repeat_penalty TINYINT NOT NULL DEFAULT 0');
        }
        // Organisers' packs written before one answer per line (format 2): their lines without a
        // comma were split on spaces ("quark quarks"), and keep that meaning.
        if (!$db->query("SHOW COLUMNS FROM packs LIKE 'format'")->fetch()) {
            $db->exec('ALTER TABLE packs ADD COLUMN format TINYINT UNSIGNED NOT NULL DEFAULT 1');
        }
        // Running totals for the overall leaderboard, so polls don't re-add every word and miss.
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'score'")->fetch()) {
            $db->exec('ALTER TABLE players
                ADD COLUMN score INT NOT NULL DEFAULT 0,
                ADD COLUMN words INT UNSIGNED NOT NULL DEFAULT 0,
                ADD KEY idx_score (world_id, score)');
            self::recountScores($db);
        }
        // Streaks (days in a row with a word said) and the optional daily email.
        if (!$db->query("SHOW COLUMNS FROM users LIKE 'streak'")->fetch()) {
            $db->exec('ALTER TABLE users
                ADD COLUMN streak SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN streak_best SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN streak_day CHAR(10) CHARACTER SET ascii NULL,
                ADD COLUMN reminders TINYINT NOT NULL DEFAULT 0,
                ADD COLUMN reminder_day CHAR(10) CHARACTER SET ascii NULL,
                ADD COLUMN reminder_token CHAR(32) CHARACTER SET ascii NULL,
                ADD KEY idx_reminders (reminders)');
        }
        // What was left unsaid when each prompt ended: how many answers, and the commonest few.
        if (!$db->query("SHOW COLUMNS FROM prompts LIKE 'unsaid_count'")->fetch()) {
            $db->exec('ALTER TABLE prompts ADD COLUMN unsaid_count INT UNSIGNED NULL, ADD COLUMN unsaid_words TEXT NULL');
        }
        // How a missed word was typed ("double decker"), for My misses; the word column has it joined.
        if (!$db->query("SHOW COLUMNS FROM misses LIKE 'written'")->fetch()) {
            $db->exec('ALTER TABLE misses ADD COLUMN written VARCHAR(60) NULL');
        }
        // A competition player moved by the organiser: the team they were on, to tell them.
        if (!$db->query("SHOW COLUMNS FROM players LIKE 'moved_from'")->fetch()) {
            $db->exec('ALTER TABLE players ADD COLUMN moved_from VARCHAR(40) NULL');
        }
        // The word that finished a prompt (it got the closing bonus), for the feed.
        if (!$db->query("SHOW COLUMNS FROM prompts LIKE 'closing_word'")->fetch()) {
            $db->exec('ALTER TABLE prompts ADD COLUMN closing_word VARCHAR(40) NULL');
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
     * Recalculates players' running totals from the words and misses tables. Run
     * automatically when the totals are added; run it again after editing those tables by hand.
     * Finished worlds are left alone: housekeeping deletes their detail, but their final
     * scores stand.
     */
    public static function recountScores(PDO $db, ?string $worldId = null): void
    {
        $db->prepare('
            UPDATE players p JOIN worlds w ON w.id = p.world_id SET
              p.score = (SELECT COALESCE(SUM(points), 0) FROM burns WHERE player_id = p.id)
                      + (SELECT COALESCE(SUM(points), 0) FROM misses WHERE player_id = p.id),
              p.words = (SELECT COUNT(*) FROM burns WHERE player_id = p.id AND word = played_word)
            WHERE (? IS NULL OR p.world_id = ?) AND w.ended_at IS NULL')
            ->execute([$worldId, $worldId]);
        $db->prepare('UPDATE worlds SET version = version + 1 WHERE ? IS NULL OR id = ?')->execute([$worldId, $worldId]);
    }

    public static function newInviteCode(): string
    {
        return bin2hex(random_bytes(5));
    }

    public function dictionary(): Dictionary
    {
        return $this->dictionary;
    }

    public function dictionarySize(): int
    {
        return $this->dictionary->size();
    }

    /** Marks the world's shared state as changed, for the state cache. */
    public function changed(string $worldId): void
    {
        $this->db->prepare('UPDATE worlds SET version = version + 1 WHERE id = ?')->execute([$worldId]);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    // --- Worlds and players ---

    /**
     * @param array{kind?: string, startsAt?: int, endsAt?: int, promptMin?: int, promptMax?: int, maxPlayers?: int, teamSize?: int,
     *   pack?: string, packMode?: string} $options
     *   kind: 'private' (default), 'public' (a round of the big world, ending at endsAt), 'room',
     *   or 'competition' (with teams, see Competitions, starting at startsAt);
     *   promptMin/promptMax: how many answers each prompt should have;
     *   pack/packMode: a word pack the world plays, alone ('only') or with everyday words ('mixed').
     */
    public function createWorld(string $name, ?string $id = null, int $promptDurationMs = self::DEFAULT_PROMPT_DURATION_MS, string $spelling = 'both', ?int $ownerUserId = null, array $options = []): array
    {
        $id ??= bin2hex(random_bytes(4));
        $name = mb_substr(trim($name), 0, 40) ?: 'Unnamed room';
        if (!in_array($spelling, self::SPELLINGS, true)) throw new GameError(400, 'Spelling must be uk, us or both');
        $this->db->prepare('
            INSERT INTO worlds (id, name, kind, prompt_duration_ms, spelling, owner_user_id, prompt_min, prompt_max, ends_at,
              invite_code, max_players, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $id, $name, $kind = $options['kind'] ?? 'private', $promptDurationMs, $spelling, $ownerUserId,
                $options['promptMin'] ?? null, $options['promptMax'] ?? null, $options['endsAt'] ?? null,
                in_array($kind, ['private', 'competition'], true) ? self::newInviteCode() : null, $options['maxPlayers'] ?? null, $this->now(),
            ]);
        if (isset($options['pack'])) {
            $this->db->prepare('UPDATE worlds SET pack = ?, pack_mode = ? WHERE id = ?')
                ->execute([$options['pack'], $options['packMode'] ?? 'only', $id]);
        }
        if (!empty($options['repeatPenalty'])) $this->db->prepare('UPDATE worlds SET repeat_penalty = 1 WHERE id = ?')->execute([$id]);
        if (isset($options['startsAt']) || isset($options['teamSize'])) {
            $this->db->prepare('UPDATE worlds SET starts_at = ?, team_size = ? WHERE id = ?')
                ->execute([$options['startsAt'] ?? null, $options['teamSize'] ?? null, $id]);
        }
        // A pack world's first prompt comes from the pack, picked when the world is first opened.
        if (!isset($options['pack'])) $this->currentPrompt($id);
        return $this->world($id);
    }

    public function world(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM worlds WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: throw new GameError(404, 'Room not found');
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
            if ($e->getCode() === '23000') throw new GameError(409, 'That nickname is taken in this room');
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
        throw new GameError(409, 'Could not join this room');
    }

    /** Gives a player a new name, with a number if someone in the world already has it. Returns the name given. */
    public function renamePlayer(string $worldId, int $playerId, string $name): string
    {
        for ($n = 1; $n <= 500; $n++) {
            $suffix = $n === 1 ? '' : " $n";
            $candidate = mb_substr($name, 0, 20 - mb_strlen($suffix)) . $suffix;
            try {
                $this->db->prepare('UPDATE players SET nickname = ? WHERE id = ?')->execute([$candidate, $playerId]);
                $this->changed($worldId);
                return $candidate;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
            }
        }
        throw new GameError(409, 'Could not rename the player');
    }

    private function playerByToken(string $worldId, ?string $token): array
    {
        $stmt = $this->db->prepare('SELECT * FROM players WHERE world_id = ? AND token = ?');
        $stmt->execute([$worldId, (string) $token]);
        $player = $stmt->fetch() ?: throw new GameError(401, 'Join this room first');
        if ($player['removed']) throw new GameError(403, "You've been removed from this room");
        return $player;
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

    /**
     * A typed word as the dictionary has it: lower case, with spaces, hyphens and apostrophes
     * dropped, so "tea towel" and "T-shirt" are played as teatowel and tshirt.
     */
    public static function typed(string $rawWord): string
    {
        return Dictionary::joined($rawWord);
    }

    public function lookup(string $worldId, string $rawWord): array
    {
        $this->world($worldId);
        $word = self::typed($rawWord);
        $stmt = $this->db->prepare('
            SELECT b.played_word, b.burned_at, b.player_id, p.nickname, pr.spec, pr.label, pr.type, pr.letters
            FROM burns b JOIN players p ON p.id = b.player_id JOIN prompts pr ON pr.id = b.prompt_id
            WHERE b.world_id = ? AND b.word = ?');
        $stmt->execute([$worldId, $word]);
        $row = $stmt->fetch();
        return [
            'word' => $this->dictionary->display($word),
            'inDictionary' => $this->dictionary->has($word),
            'burned' => $row ? [
                'by' => $row['nickname'],
                'playerId' => (int) $row['player_id'],
                'at' => (int) $row['burned_at'],
                'playedWord' => $this->dictionary->display($row['played_word']),
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
        $finished = $prompt['remaining'] <= ($this->inLastMinutes($worldId) ? 0 : self::endsWhenLeft((int) $prompt['available_at_start']));
        // A prompt never outlives its world, even if the organiser moved the end earlier.
        $worldEndsAt = $this->world($worldId)['ends_at'] ?? null;
        $over = $this->now() >= (int) $prompt['ends_at'] || ($worldEndsAt !== null && $this->now() >= (int) $worldEndsAt);
        return !$finished && !$over ? $prompt : null;
    }

    /** Whether the world ends within LAST_PROMPT_MS: then the prompt runs on to the end. */
    private function inLastMinutes(string $worldId): bool
    {
        $endsAt = $this->world($worldId)['ends_at'] ?? null;
        return $endsAt !== null && $this->now() >= (int) $endsAt - self::LAST_PROMPT_MS;
    }

    /** A prompt that started with this many answers ends when this many are left (see END_SHARE). */
    public static function endsWhenLeft(int $availableAtStart): int
    {
        return (int) floor($availableAtStart * (1 - self::END_SHARE) + 1e-9);
    }

    /** A stored prompt's spec. Prompts from before the spec column were simple letter patterns. */
    public static function specOf(array $row): array
    {
        if (!empty($row['spec'])) return json_decode($row['spec'], true);
        return ['cat' => null, 'rules' => [[$row['type'], $row['letters']]]];
    }

    /** Returns the active prompt, rotating it first if it has expired or run dry. Null when there is none: the world has ended, run out of words or not started yet. */
    public function currentPrompt(string $worldId): ?array
    {
        $world = $this->world($worldId);
        if ($world['ended_at'] ?? null) return null;
        // A competition has no prompt until it starts.
        if (isset($world['starts_at']) && $this->now() < (int) $world['starts_at']) return null;
        return $this->livePrompt($worldId) ?? $this->withWorldLock($worldId, function () use ($worldId) {
            if ($prompt = $this->livePrompt($worldId)) return $prompt; // Another request rotated it first.
            $world = $this->world($worldId);
            if ($world['ended_at'] ?? null) return null; // Another request finished it first.
            $this->endOpenPrompts($worldId);
            $this->changed($worldId);
            $endsAt = isset($world['ends_at']) ? (int) $world['ends_at'] : null;
            if ($endsAt !== null && $this->now() >= $endsAt) {
                $this->finishWorld($worldId); // The round's time is up.
                return null;
            }
            $stmt = $this->db->prepare('SELECT spec, type, letters FROM prompts WHERE world_id = ? ORDER BY id');
            $stmt->execute([$worldId]);
            $used = [];
            $lastCat = null;
            foreach ($stmt->fetchAll() as $row) {
                $used[Prompts::key($spec = self::specOf($row))] = true;
                $lastCat = $spec['cat'] ?? null;
            }
            $next = Prompts::pick(
                $this->dictionary, $this->unavailable($worldId), $used,
                (int) ($world['prompt_min'] ?? ($world['kind'] === 'room' ? self::ROOM_PROMPT_ANSWERS[0] : $this->promptMin)),
                (int) ($world['prompt_max'] ?? ($world['kind'] === 'room' ? self::ROOM_PROMPT_ANSWERS[1] : $this->promptMax)),
                avoidCat: $lastCat,
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
                    // A round's last prompt stops when the round does; one that would end in the last few
                    // minutes runs on to the end, so no prompt starts with only minutes to go.
                    $next['available'], $start, ($endsAt !== null && $endsAt - ($start + (int) $world['prompt_duration_ms']) < self::LAST_PROMPT_MS)
                        ? $endsAt : $start + (int) $world['prompt_duration_ms'],
                ]);
            $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ?');
            $stmt->execute([$this->db->lastInsertId()]);
            return ['spec' => $next['spec'], 'remaining' => $next['available']] + $stmt->fetch();
        });
    }

    /** How many of the commonest unsaid answers a finished prompt shows. */
    public const UNSAID_SHOWN = 12;

    /**
     * Ends the world's open prompt, noting what was left unsaid: the number of answers, and
     * the commonest few (one word per family; masked words are counted but not shown), for
     * the "Nobody said" reveal. Noted now, as later prompts use words up too.
     */
    private function endOpenPrompts(string $worldId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM prompts WHERE world_id = ? AND ended_at IS NULL');
        $stmt->execute([$worldId]);
        $update = $this->db->prepare('UPDATE prompts SET ended_at = ?, unsaid_count = ?, unsaid_words = ? WHERE id = ?');
        unset($this->burnedCache[$worldId]);
        foreach ($stmt->fetchAll() as $prompt) {
            $unsaid = Prompts::unsaid(self::specOf($prompt), $this->dictionary, $this->unavailable($worldId));
            $shown = array_slice(array_values(array_filter($unsaid, fn ($w) => !$this->dictionary->isMasked($w))), 0, self::UNSAID_SHOWN);
            $update->execute([$this->now(), count($unsaid), json_encode($shown), $prompt['id']]);
        }
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
                SELECT p.id, p.user_id, p.nickname, p.score, p.words, t.name AS team_name FROM players p
                LEFT JOIN teams t ON t.id = p.team_id
                WHERE p.world_id = ? AND p.removed = 0 AND (p.words > 0 OR p.score <> 0)
                ORDER BY p.score DESC, p.words DESC, p.nickname');
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
                INSERT INTO results (world_id, user_id, nickname, place, players, score, words, best_word, best_points, team_name, ended_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $place = 0;
            foreach ($players as $i => $p) {
                if ($i === 0 || $p['score'] !== $players[$i - 1]['score']) $place = $i + 1;
                $b = $best[$p['id']] ?? null;
                $insert->execute([
                    $worldId, $p['user_id'], $p['nickname'], $place, count($players), $p['score'], $p['words'],
                    $b['played_word'] ?? null, $b['points'] ?? null, $p['team_name'], $now,
                ]);
            }
            // A competition's teams, every team placed (an empty one scores 0).
            $world = $this->world($worldId);
            if (($world['kind'] ?? '') === 'competition') {
                $teams = $this->teamStandings($worldId, -1);
                $insert = $this->db->prepare('
                    INSERT INTO team_results (world_id, team_id, name, place, teams, score, players, ended_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $place = 0;
                foreach ($teams as $i => $t) {
                    if ($i === 0 || $t['score'] !== $teams[$i - 1]['score']) $place = $i + 1;
                    $insert->execute([$worldId, $t['id'], $t['name'], $place, count($teams), $t['score'], count($t['players']), $now]);
                }
            }
            $this->db->prepare('UPDATE worlds SET ended_at = ? WHERE id = ?')->execute([$now, $worldId]);
            $this->endOpenPrompts($worldId);
            $this->changed($worldId);
        });
    }

    /**
     * The world multiplier rises as a world's words are used up, which suits rooms and private
     * worlds, played over weeks. In timed games (the big world's daily round, competitions) it
     * could climb fast on a busy day, so the clock would decide more than the words; in a small
     * pack it soared within minutes (Kitchen Cup playtest). Those go without.
     */
    public static function usesWorldMultiplier(array $world): bool
    {
        $packOnly = !empty($world['pack']) && ($world['pack_mode'] ?? 'only') === 'only';
        return in_array($world['kind'] ?? 'private', ['room', 'private'], true) && !$packOnly;
    }

    /** The world multiplier (null where it isn't used) and the prompt's. */
    private function multipliers(string $worldId, ?array $prompt): array
    {
        return [
            'world' => !self::usesWorldMultiplier($this->world($worldId)) ? null
                : round(Scoring::worldMultiplier(count($this->burned($worldId)), $this->dictionary->size()), 3),
            'prompt' => $prompt ? Scoring::promptBonus((int) $prompt['remaining'], (int) $prompt['available_at_start']) : 1.0,
        ];
    }

    // --- Playing ---

    /**
     * Plays a word. $promptId is the prompt the player was looking at: if it changed in the last
     * PROMPT_GRACE_MS, the word is judged against that one instead, so a word typed just before
     * a change isn't wasted or penalised.
     */
    public function play(string $worldId, ?string $token, string $rawWord, ?int $promptId = null): array
    {
        $player = $this->playerByToken($worldId, $token);
        $word = mb_substr(self::typed($rawWord), 0, 40);
        // Rejections echo what the player typed ("barn owl", not "barnowl"), unless the word
        // lists say how it's written.
        $typed = mb_substr(preg_replace('/\s+/u', ' ', mb_strtolower(trim($rawWord))), 0, 60);
        $this->checkRateLimit($player);
        return $this->withWorldLock($worldId, function () use ($worldId, $player, $word, $typed, $promptId) {
            unset($this->burnedCache[$worldId]); // Re-read under the lock.
            $prompt = $this->currentPrompt($worldId);
            // Not once the world has finished: then there's no current prompt.
            $previous = $prompt && $promptId !== null && $promptId !== (int) $prompt['id'] ? $this->justEnded($worldId, $promptId) : null;
            $prompt = $previous ?? $prompt ?? throw $this->noPromptError($this->world($worldId));
            $shown = $this->dictionary->display($word) !== $word ? $this->dictionary->display($word) : $typed;
            $miss = fn (string $reason, int $penalty, array $extra = []) =>
                ['word' => $shown] + $this->miss($player, $prompt, $word, $reason, $previous ? 0 : $penalty, $shown) + $extra
                + ($previous ? ['previousPrompt' => $prompt['label'] ?? Prompts::describe($prompt['spec'], $this->dictionary)] : []);

            // Typos aren't penalised, but a word that doesn't fit or was already said costs points,
            // so pasting a list of answers from a word-finder site loses more than it gains.
            if (!$this->dictionary->has($word)) return $miss('not-a-word', 0);
            // The other side's spelling ("color" in a UK world): free, with the right spelling.
            $wrong = $this->wrongSpellings($worldId);
            if (isset($wrong[$word])) {
                return $miss('wrong-spelling', 0, [
                    'spelling' => $this->world($worldId)['spelling'],
                    'suggestions' => array_map([$this->dictionary, 'display'],
                        array_values(array_filter($this->dictionary->otherSpellings($word), fn ($w) => !isset($wrong[$w])))),
                ]);
            }
            // The letter rules first, so a word that breaks them is told why, even if it's used up.
            $letters = ['cat' => null] + $prompt['spec'];
            if (!Prompts::matches($letters, $this->dictionary, $word)) {
                return $miss('doesnt-fit', self::PENALTY, ['why' => Prompts::whyNot($letters, $this->dictionary, $word)]);
            }
            // Already said comes before the meaning, so nobody is told to report a word that's used
            // up anyway. A repeat is free unless the world's host chose to charge for it; even then,
            // your own word is an honest slip, so it's free; so is a masked word, which others only
            // saw as p***s, and a word someone said moments ago.
            $burned = $this->burned($worldId);
            if (isset($burned[$word])) {
                $said = $this->lookup($worldId, $word)['burned'];
                $mine = $said && $said['playerId'] === (int) $player['id'];
                $masked = $said && $this->dictionary->isMasked(self::typed($said['playedWord']));
                $justNow = $said && $said['at'] > $this->now() - self::SAID_GRACE_MS;
                // In a competition, a team-mate's word is free too: only an opponent's costs.
                $teamMate = !$mine && $said && $player['team_id'] !== null && $this->teamOf($said['playerId']) === (int) $player['team_id'];
                // The other spelling of a word already said ("aluminum" after "aluminium") is the same answer.
                $playedJoined = self::typed($said['playedWord'] ?? '');
                $otherSpelling = $said && in_array($playedJoined, $this->dictionary->otherSpellings($word), true);
                $charged = !empty($this->world($worldId)['repeat_penalty']);
                return $miss('already-burned', !$charged || $mine || $masked || $justNow || $teamMate ? 0 : self::PENALTY,
                    ['burned' => $said, 'byYou' => $mine]
                    + ($justNow && !$mine ? ['justNow' => true] : [])
                    + ($teamMate ? ['teamMate' => true] : [])
                    + ($otherSpelling ? ['otherSpelling' => true] : []));
            }
            // Right letters but not on our list for the meaning: our lists have gaps, so no penalty,
            // and the player can report the word.
            if (!Prompts::matches($prompt['spec'], $this->dictionary, $word)) {
                return $miss('not-in-category', 0, ['category' => $this->dictionary->categoryLabel($prompt['spec']['cat'])]);
            }

            $family = array_values(array_filter($this->dictionary->family($word), fn ($w) => !isset($burned[$w])));
            // Does it count towards the prompt's answers? Answers a category accepts without counting
            // ("also" words) score without the prompt bonus, as at the start: they don't bring the
            // count down, so they'd otherwise ride the top bonus for as long as the count stays put.
            $remaining = (int) $prompt['remaining'];
            // A letter prompt counts every fitting word, so only a meaning prompt needs counting again.
            $after = isset($prompt['spec']['cat'])
                ? Prompts::countUnsaid($prompt['spec'], $this->dictionary, $this->unavailable($worldId) + array_fill_keys($family, true))
                : $remaining - 1;
            $counted = $after < $remaining;
            $mult = $this->multipliers($worldId, $prompt);
            if (!$counted) $mult['prompt'] = 1.0;
            // A plural scores as its singular, so adding an S earns nothing extra; on a letter prompt,
            // a verb's -ing or -ed form scores as the verb, so adding -ing earns nothing either.
            $singular = $this->dictionary->singular(Prompts::judged($prompt['spec'], $this->dictionary, $word));
            // A phrase scores by its words as written ("paddle steamer"), not its joined length.
            $written = $this->dictionary->display($singular);
            $points = Scoring::scoreWord($written, $this->rarityOf($singular, $written), $mult['world'] ?? 1.0, $mult['prompt']);
            // The answer that finishes the prompt (see END_SHARE) earns a flat bonus.
            $closing = !$previous && $counted && $after <= ($this->inLastMinutes($worldId) ? 0 : self::endsWhenLeft((int) $prompt['available_at_start']));
            if ($closing) {
                $points += Scoring::CLOSING_BONUS;
                $this->db->prepare('UPDATE prompts SET closing_word = ? WHERE id = ?')->execute([$word, $prompt['id']]);
            }
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
            // The day's first word: the streak it makes.
            $streak = $player['user_id'] !== null ? $this->streaks?->played((int) $player['user_id']) : null;
            return [
                'ok' => true,
                'word' => $this->dictionary->display($word),
                'points' => $points,
                'alsoBurned' => array_map([$this->dictionary, 'display'], array_values(array_filter($family, fn ($w) => $w !== $word))),
                'multipliers' => $mult,
            ] + ($counted ? [] : ['uncounted' => true]) + ($closing ? ['closing' => true] : [])
              + ($previous ? ['previousPrompt' => $prompt['label'] ?? Prompts::describe($prompt['spec'], $this->dictionary)] : [])
              + ($streak !== null ? ['streak' => $streak] : []);
        });
    }

    /**
     * How rare a word is, for scoring. A phrase ("paddle steamer") is mostly hand-added, so it
     * would count as one of the rarest words; it takes the rarity of its longest word instead
     * ("steamer"), the word it's scored on, when the dictionary knows that word.
     */
    private function rarityOf(string $word, string $written): float
    {
        $parts = preg_split('/[\s\-]+/', $written, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) > 1) {
            usort($parts, fn ($a, $b) => strlen(self::typed($b)) <=> strlen(self::typed($a)));
            $rarity = $this->dictionary->knownRarity(self::typed($parts[0]));
            if ($rarity !== null) return $rarity;
        }
        return $this->dictionary->rarity($word);
    }

    /** A player's competition team, or null. */
    private function teamOf(int $playerId): ?int
    {
        $stmt = $this->db->prepare('SELECT team_id FROM players WHERE id = ?');
        $stmt->execute([$playerId]);
        $team = $stmt->fetchColumn();
        return $team === false || $team === null ? null : (int) $team;
    }

    /**
     * A prompt of this world that ended in the last PROMPT_GRACE_MS, ready to judge a word against
     * as it stood when it ended (its unsaid count then), or null.
     */
    private function justEnded(string $worldId, int $promptId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ? AND world_id = ? AND ended_at IS NOT NULL AND ended_at > ?');
        $stmt->execute([$promptId, $worldId, $this->now() - self::PROMPT_GRACE_MS]);
        $prompt = $stmt->fetch();
        if (!$prompt) return null;
        $prompt['spec'] = self::specOf($prompt);
        $prompt['remaining'] = Prompts::countUnsaid($prompt['spec'], $this->dictionary, $this->unavailable($worldId));
        return $prompt;
    }

    private function noPromptError(array $world): GameError
    {
        if (isset($world['starts_at']) && $this->now() < (int) $world['starts_at'] && !$world['ended_at']) {
            return new GameError(409, "The competition hasn't started yet.");
        }
        return new GameError(410, $this->endedMessage($world));
    }

    /** Why a finished world can't be played any more: for someone trying to play, or looking at it. */
    public function endedMessage(array $world, bool $playing = true): string
    {
        return match ($world['kind'] ?? 'private') {
            'public' => $playing ? 'This round has finished. Reload the page for the new one.' : 'This round of the big room has finished.',
            'room' => 'This room has run out of words. Join a new public room from the home page.',
            'competition' => 'This competition has finished.',
            default => 'This room has run out of words',
        };
    }

    private function miss(array $player, array $prompt, string $word, string $reason, int $penalty, ?string $written = null): array
    {
        $this->db->prepare('
            INSERT INTO misses (world_id, player_id, prompt_id, word, written, reason, points, missed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$player['world_id'], $player['id'], $prompt['id'], $word, $written !== $word ? $written : null, $reason, -$penalty, $this->now()]);
        $this->db->prepare('UPDATE players SET score = score - ?, last_active_at = ? WHERE id = ?')
            ->execute([$penalty, $this->now(), $player['id']]);
        // Penalties show on the leaderboards; free misses are private.
        if ($penalty) $this->changed($player['world_id']);
        // promptId: the prompt it was judged against, which a report of it should be about.
        return ['ok' => false, 'word' => $this->dictionary->display($word), 'reason' => $reason, 'penalty' => $penalty, 'promptId' => (int) $prompt['id']];
    }

    /**
     * Records a player's report that a word should count: for the current prompt's meaning,
     * or as a real word missing from the dictionary. The admin page reviews reports.
     */
    public function report(string $worldId, ?string $token, string $rawWord, ?int $promptId = null): array
    {
        $player = $this->playerByToken($worldId, $token);
        $word = self::typed($rawWord);
        if (!preg_match('/^[a-z]{3,30}$/', $word)) throw new GameError(400, 'Only words of 3 to 30 letters can be reported');
        // The prompt the miss was for (My misses lists earlier ones too), or the current one.
        $prompt = null;
        if ($promptId !== null) {
            $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ? AND world_id = ?');
            $stmt->execute([$promptId, $worldId]);
            if ($row = $stmt->fetch()) $prompt = ['spec' => self::specOf($row)] + $row;
        }
        $prompt ??= $this->currentPrompt($worldId) ?? throw $this->noPromptError($this->world($worldId));
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

    /**
     * The words said in the world's latest prompts (the current one first), each with all
     * its words, newest first. Older prompts' words can still be found with lookup().
     */
    private function promptsPlayed(string $worldId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT prompt_id) FROM burns WHERE world_id = ?');
        $stmt->execute([$worldId]);
        return (int) $stmt->fetchColumn();
    }

    private function saidByPrompt(string $worldId, int $prompts = 4, array $teamOf = [], ?array $onlyPromptIds = null): array
    {
        // The current prompt and the latest ones that had words: prompts nobody played aren't
        // shown, so they mustn't crowd out the ones that were.
        $only = $onlyPromptIds !== null ? ' AND p.id IN (' . implode(',', array_map('intval', $onlyPromptIds ?: [0])) . ')'
            : ' AND (p.ended_at IS NULL OR EXISTS (SELECT 1 FROM burns b WHERE b.prompt_id = p.id))';
        $stmt = $this->db->prepare("SELECT p.id, p.spec, p.label, p.type, p.letters, p.unsaid_count, p.unsaid_words, p.closing_word
            FROM prompts p WHERE p.world_id = ?$only ORDER BY p.id DESC LIMIT $prompts");
        $stmt->execute([$worldId]);
        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groups[(int) $row['id']] = [
                'promptId' => (int) $row['id'],
                'prompt' => $row['label'] ?? Prompts::describe(self::specOf($row), $this->dictionary),
                'words' => [],
            ] + ($row['unsaid_count'] === null ? [] : [
                // An ended prompt: what nobody said.
                'unsaid' => ['count' => (int) $row['unsaid_count'],
                    'words' => array_map([$this->dictionary, 'display'], json_decode((string) $row['unsaid_words'], true) ?: [])],
            ]) + ($row['closing_word'] === null ? [] : ['closingWord' => $this->dictionary->display($row['closing_word'])]);
        }
        if (!$groups) return [];
        $ids = implode(',', array_keys($groups)); // Integers from the database.
        $stmt = $this->db->prepare("
            SELECT b.word, b.points, b.burned_at, b.prompt_id, p.nickname, p.team_id,
              (SELECT COUNT(*) - 1 FROM burns f WHERE f.world_id = b.world_id AND f.played_word = b.word) AS family_count
            FROM burns b JOIN players p ON p.id = b.player_id
            WHERE b.world_id = ? AND b.word = b.played_word AND b.prompt_id IN ($ids)
            ORDER BY b.id DESC");
        $stmt->execute([$worldId]);
        foreach ($stmt->fetchAll() as $r) {
            $groups[(int) $r['prompt_id']]['words'][] = [
                // Shown as written ("tea towel"); a masked word is kept as it is, for the page to mask.
                'word' => $this->dictionary->display($r['word']), 'points' => (int) $r['points'], 'by' => $r['nickname'],
                'at' => (int) $r['burned_at'], 'familyCount' => (int) $r['family_count'],
            ] + ($this->dictionary->isMasked($r['word']) ? ['masked' => true] : [])
              + (isset($teamOf[$r['team_id']]) ? ['team' => $teamOf[$r['team_id']]] : []);
        }
        return array_values($groups);
    }

    /** The latest ended prompt with at least one word said, with its words and what was left unsaid. */
    public function recentPrompt(string $worldId, array $teamOf = []): ?array
    {
        return $this->recentPrompts($worldId, 1, $teamOf)[0] ?? null;
    }

    /** The latest ended prompts with at least one word said, newest first, as recentPrompt() gives them. */
    public function recentPrompts(string $worldId, int $count, array $teamOf = []): array
    {
        $stmt = $this->db->prepare('
            SELECT p.id FROM prompts p
            WHERE p.world_id = ? AND p.ended_at IS NOT NULL AND p.unsaid_count IS NOT NULL
              AND EXISTS (SELECT 1 FROM burns b WHERE b.prompt_id = p.id)
            ORDER BY p.id DESC LIMIT ' . max(1, $count));
        $stmt->execute([$worldId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $ids ? $this->saidByPrompt($worldId, count($ids), $teamOf, $ids) : [];
    }

    /** The words a player has said, newest first, with the prompt each was for and its points. */
    private function wordsOf(int $playerId, int $limit = 300): array
    {
        $stmt = $this->db->prepare("
            SELECT b.played_word, b.points, b.burned_at, b.prompt_id, p.spec, p.label, p.type, p.letters
            FROM burns b JOIN prompts p ON p.id = b.prompt_id
            WHERE b.player_id = ? AND b.word = b.played_word
            ORDER BY b.id DESC LIMIT $limit");
        $stmt->execute([$playerId]);
        return array_map(fn ($r) => [
            'word' => $this->dictionary->display($r['played_word']),
            'points' => (int) $r['points'],
            'at' => (int) $r['burned_at'],
            'promptId' => (int) $r['prompt_id'],
            'prompt' => $r['label'] ?? Prompts::describe(self::specOf($r), $this->dictionary),
        ], $stmt->fetchAll());
    }

    /** A player's most recent wrong guesses, newest first, with the prompt each was for. */
    private function missesOf(int $playerId, int $limit = 50): array
    {
        // Report status: the player's report of this word for this prompt, if any.
        $stmt = $this->db->prepare("
            SELECT m.word, m.written, m.reason, m.points, m.missed_at, m.prompt_id, p.spec, p.label, p.type, p.letters,
              m.world_id, r.id AS report_id, r.category AS report_category, r.reviewed_at
            FROM misses m
            JOIN prompts p ON p.id = m.prompt_id
            LEFT JOIN reports r ON r.player_id = m.player_id AND r.prompt_id = m.prompt_id AND r.word = m.word
            WHERE m.player_id = ?
            ORDER BY m.id DESC LIMIT $limit");
        $stmt->execute([$playerId]);
        $rows = $stmt->fetchAll();

        // Whether each word has been used up since (by anyone, for any prompt). Looked up
        // separately: joining misses to burns can't use the index, as their word columns differ.
        $saidSince = [];
        // Only words of a to z can have been said: burns.word is ASCII, and the database refuses to
        // compare it with anything else (an emoji, say).
        $words = array_values(array_unique(array_filter(array_column($rows, 'word'), fn ($w) => preg_match('/^[a-z]+$/', $w))));
        if ($words) {
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
                // As typed for a word our lists don't write ("double decker"); reports send it back joined.
                'word' => $r['written'] ?? $this->dictionary->display($r['word']),
                'reason' => $r['reason'],
                'penalty' => -(int) $r['points'],
                'prompt' => $r['label'] ?? Prompts::describe(self::specOf($r), $this->dictionary),
                'promptId' => (int) $r['prompt_id'],
                'at' => (int) $r['missed_at'],
                // null (not reported), 'reported' (waiting), 'accepted' or 'dismissed'.
                'reportStatus' => $r['report_id'] ? $this->reportStatus($r['report_category'], $r['word'], $r['reviewed_at'] !== null) : null,
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

    /**
     * What became of a report, judged by the word files: 'accepted' once the word counts (after
     * an update to the dictionary), 'dismissed' if the admin reviewed it and it still doesn't,
     * and 'reported' while it waits.
     */
    public function reportStatus(string $category, string $word, bool $reviewed): string
    {
        $counts = $category === Admin::DICTIONARY_ONLY ? $this->dictionary->has($word) : $this->dictionary->inCategory($category, $word);
        return $counts ? 'accepted' : ($reviewed ? 'dismissed' : 'reported');
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
        $myWords = [];
        $myStanding = null;
        if ($token) {
            $stmt = $this->db->prepare('SELECT id, nickname FROM players WHERE world_id = ? AND token = ? AND removed = 0');
            $stmt->execute([$worldId, $token]);
            if ($player = $stmt->fetch()) {
                $me = $player['nickname'];
                $myMisses = $this->missesOf((int) $player['id']);
                $myWords = $this->wordsOf((int) $player['id']);
                $myStanding = $this->standing($worldId, (int) $player['id']);
            }
        }
        return [
            'me' => $me,
            // Where the player stands overall, for a player beyond the leaderboard's list; null until they've played.
            'myStanding' => $myStanding,
            // Only the player's own misses: other players never see them.
            'myMisses' => $myMisses,
            // Every word the player has said here (the latest 300), with its prompt: Just said only
            // shows the last few prompts.
            'myWords' => $myWords,
        ] + $shared + ['now' => $this->now()];
    }

    /** A player's place on the overall leaderboard (shared on equal scores), out of everyone who has played. */
    private function standing(string $worldId, int $playerId): ?array
    {
        $stmt = $this->db->prepare('SELECT score, words FROM players WHERE id = ?');
        $stmt->execute([$playerId]);
        $mine = $stmt->fetch();
        if (!$mine || ((int) $mine['words'] === 0 && (int) $mine['score'] === 0)) return null;
        $stmt = $this->db->prepare('
            SELECT COUNT(*), COALESCE(SUM(score > ?), 0) FROM players
            WHERE world_id = ? AND removed = 0 AND (words > 0 OR score <> 0)');
        $stmt->execute([(int) $mine['score'], $worldId]);
        [$of, $ahead] = $stmt->fetch(PDO::FETCH_NUM);
        return ['place' => (int) $ahead + 1, 'of' => (int) $of, 'score' => (int) $mine['score'], 'words' => (int) $mine['words']];
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

        $isCompetition = ($world['kind'] ?? '') === 'competition';
        $teams = $isCompetition ? $this->teamStandings($worldId, $promptId) : null;
        $teamOf = $teams ? array_column($teams, 'position', 'id') : [];
        $withTeam = fn (array $row, $teamId) => $isCompetition && isset($teamOf[$teamId]) ? $row + ['team' => $teamOf[$teamId]] : $row;

        // Scores include penalties for wrong guesses. Kept as running totals on each player.
        $stmt = $this->db->prepare('
            SELECT nickname, score, words, last_active_at, team_id FROM players WHERE world_id = ? AND removed = 0
            ORDER BY score DESC, words DESC, nickname LIMIT 50');
        $stmt->execute([$worldId]);
        // In rooms, players who've stopped playing show as away: their seat is free for someone new.
        $awaySince = ($world['kind'] ?? '') === 'room' && !$world['ended_at'] ? $this->now() - $this->awayAfterMs : null;
        $leaderboard = array_map(fn ($r) => $withTeam([
            'nickname' => $r['nickname'], 'total' => (int) $r['score'], 'words' => (int) $r['words'],
            // When they last said or tried a word, so the page can mark who's playing now.
            'lastActive' => (int) $r['last_active_at'],
        ] + ($awaySince !== null && (int) $r['last_active_at'] < $awaySince ? ['away' => true] : []), $r['team_id']), $stmt->fetchAll());

        // The same, ranked by the current prompt alone, so late arrivals can top something.
        // Only players who have said a word or lost points in this prompt are listed (free misses don't count).
        $stmt = $this->db->prepare('
            SELECT p.nickname, p.team_id, p.last_active_at, COALESCE(b.words, 0) AS words, COALESCE(b.points, 0) + COALESCE(m.points, 0) AS points
            FROM players p
            LEFT JOIN (
              SELECT player_id, COUNT(CASE WHEN word = played_word THEN 1 END) AS words, SUM(points) AS points
              FROM burns WHERE prompt_id = ? GROUP BY player_id
            ) b ON b.player_id = p.id
            LEFT JOIN (
              SELECT player_id, SUM(points) AS points FROM misses WHERE prompt_id = ? GROUP BY player_id
            ) m ON m.player_id = p.id
            WHERE p.world_id = ? AND p.removed = 0 AND (b.player_id IS NOT NULL OR COALESCE(m.points, 0) <> 0)
            ORDER BY points DESC, words DESC, p.nickname
            LIMIT 50');
        $stmt->execute([$promptId, $promptId, $worldId]);
        $promptBoard = array_map(fn ($r) => $withTeam([
            'nickname' => $r['nickname'], 'points' => (int) $r['points'], 'words' => (int) $r['words'],
            'lastActive' => (int) $r['last_active_at'],
        ], $r['team_id']), $stmt->fetchAll());

        return [
            'world' => [
                'id' => $world['id'], 'name' => $world['name'], 'spelling' => $world['spelling'] ?? 'both',
                'kind' => $world['kind'] ?? 'private',
                'closed' => (bool) ($world['closed'] ?? false),
                // Whether repeating someone else's word costs points here (free unless the host chose).
                'repeatPenalty' => (bool) ($world['repeat_penalty'] ?? false),
                'endsAt' => isset($world['ends_at']) ? (int) $world['ends_at'] : null,
                'startsAt' => isset($world['starts_at']) ? (int) $world['starts_at'] : null,
                'teamSize' => isset($world['team_size']) ? (int) $world['team_size'] : null,
                // Set once the world is over: out of words, or the round's time is up.
                'ended' => $ended,
                'endedMessage' => $ended ? $this->endedMessage($world, playing: false) : null,
            ],
            'prompt' => $prompt ? [
                'id' => (int) $prompt['id'],
                'label' => $prompt['label'] ?? Prompts::describe($prompt['spec'], $this->dictionary),
                // The list's note on its quirks, if it has one (a "note:" line in its category file).
                'note' => isset($prompt['spec']['cat']) ? $this->dictionary->categoryNote($prompt['spec']['cat']) : null,
                'remaining' => (int) $prompt['remaining'],
                'availableAtStart' => (int) $prompt['available_at_start'],
                // It ends when this many answers are left, or at endsAt, whichever comes first.
                'endsWhenLeft' => $this->inLastMinutes($worldId) ? 0 : self::endsWhenLeft((int) $prompt['available_at_start']),
                'endsAt' => (int) $prompt['ends_at'],
            ] : null,
            'multipliers' => $mult,
            'dictionary' => ['size' => $this->dictionary->size(), 'burned' => count($this->burned($worldId))],
            // Every word said in the current prompt and the few before it, newest prompt first.
            'said' => $this->saidByPrompt($worldId, teamOf: $teamOf),
            // How many prompts had words in all, so the page can say Just said shows only the latest.
            'promptsPlayed' => $this->promptsPlayed($worldId),
            // The latest finished prompt someone played, however far back, for the Recent prompt box.
            'recentPrompt' => $this->recentPrompt($worldId, $teamOf),
            'leaderboard' => $leaderboard,
            'promptBoard' => $promptBoard,
        ] + ($isCompetition ? ['teams' => $teams] : []);
    }

    /**
     * A competition's teams, best first: each team's score is the total of its players' scores
     * (penalties included), with this prompt's points, and its players' names.
     */
    public function teamStandings(string $worldId, int $promptId): array
    {
        $stmt = $this->db->prepare('SELECT id, name, position FROM teams WHERE world_id = ? ORDER BY position');
        $stmt->execute([$worldId]);
        $teams = [];
        foreach ($stmt->fetchAll() as $t) {
            $teams[(int) $t['id']] = ['id' => (int) $t['id'], 'name' => $t['name'], 'position' => (int) $t['position'],
                'score' => 0, 'thisPrompt' => 0, 'words' => 0, 'players' => []];
        }
        $stmt = $this->db->prepare('SELECT team_id, nickname, score, words FROM players WHERE world_id = ? AND removed = 0 AND team_id IS NOT NULL ORDER BY nickname');
        $stmt->execute([$worldId]);
        foreach ($stmt->fetchAll() as $p) {
            if (!isset($teams[(int) $p['team_id']])) continue;
            $team = &$teams[(int) $p['team_id']];
            $team['score'] += (int) $p['score'];
            $team['words'] += (int) $p['words'];
            $team['players'][] = $p['nickname'];
            unset($team);
        }
        foreach (['burns', 'misses'] as $table) {
            $stmt = $this->db->prepare("
                SELECT p.team_id, SUM(x.points) AS points FROM $table x JOIN players p ON p.id = x.player_id
                WHERE x.prompt_id = ? AND p.removed = 0 AND p.team_id IS NOT NULL GROUP BY p.team_id");
            $stmt->execute([$promptId]);
            foreach ($stmt->fetchAll() as $r) {
                if (isset($teams[(int) $r['team_id']])) $teams[(int) $r['team_id']]['thisPrompt'] += (int) $r['points'];
            }
        }
        $teams = array_values($teams);
        usort($teams, fn ($a, $b) => [$b['score'], $b['words'], $a['position']] <=> [$a['score'], $a['words'], $b['position']]);
        return $teams;
    }
}
