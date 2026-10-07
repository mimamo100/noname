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

    /** @var array<string, array<string, true>> worldId => burned words, cached for this request */
    private array $burnedCache = [];
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly Dictionary $dictionary,
        ?callable $clock = null,
        private readonly int $promptMin = 15,
        private readonly int $promptMax = 200,
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    public static function installSchema(PDO $db): void
    {
        foreach (array_filter(array_map('trim', explode(';', file_get_contents(dirname(__DIR__) . '/schema.sql')))) as $sql) {
            $db->exec($sql);
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
        $prompt['remaining'] = Prompts::countUnburned($this->dictionary, $prompt['type'], $prompt['letters'], $this->burned($worldId));
        return $prompt['remaining'] > 0 && $this->now() < (int) $prompt['ends_at'] ? $prompt : null;
    }

    /** Returns the active prompt, rotating it first if it has expired or run dry. Null when the world has no words left. */
    public function currentPrompt(string $worldId): ?array
    {
        return $this->livePrompt($worldId) ?? $this->withWorldLock($worldId, function () use ($worldId) {
            if ($prompt = $this->livePrompt($worldId)) return $prompt; // Another request rotated it first.
            $world = $this->world($worldId);
            $this->db->prepare('UPDATE prompts SET ended_at = ? WHERE world_id = ? AND ended_at IS NULL')->execute([$this->now(), $worldId]);
            $stmt = $this->db->prepare("SELECT CONCAT(type, ':', letters) FROM prompts WHERE world_id = ?");
            $stmt->execute([$worldId]);
            $used = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
            $next = Prompts::pick($this->dictionary, $this->burned($worldId), $used, $this->promptMin, $this->promptMax);
            if (!$next) return null;
            $start = $this->now();
            $this->db->prepare('
                INSERT INTO prompts (world_id, type, letters, available_at_start, started_at, ends_at)
                VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$worldId, $next['type'], $next['letters'], $next['available'], $start, $start + (int) $world['prompt_duration_ms']]);
            $stmt = $this->db->prepare('SELECT * FROM prompts WHERE id = ?');
            $stmt->execute([$this->db->lastInsertId()]);
            return $stmt->fetch() + ['remaining' => $next['available']];
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
        $word = strtolower(trim($rawWord));
        return $this->withWorldLock($worldId, function () use ($worldId, $player, $word) {
            unset($this->burnedCache[$worldId]); // Re-read under the lock.
            $prompt = $this->currentPrompt($worldId) ?? throw new GameError(410, 'This world has run out of words');

            if (!$this->dictionary->has($word)) return ['ok' => false, 'word' => $word, 'reason' => 'not-a-word'];
            if (!Prompts::matches($prompt['type'], $prompt['letters'], $word)) return ['ok' => false, 'word' => $word, 'reason' => 'doesnt-fit'];
            $burned = $this->burned($worldId);
            if (isset($burned[$word])) {
                return ['ok' => false, 'word' => $word, 'reason' => 'already-burned', 'burned' => $this->lookup($worldId, $word)['burned']];
            }

            $mult = $this->multipliers($worldId, $prompt);
            $points = Scoring::scoreWord($word, $this->dictionary->rarity($word), $mult['world'], $mult['prompt']);
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

    // --- State for the client ---

    public function state(string $worldId, ?string $token): array
    {
        $world = $this->world($worldId);
        $prompt = $this->currentPrompt($worldId);
        $mult = $this->multipliers($worldId, $prompt);

        $stmt = $this->db->prepare('
            SELECT b.word, b.points, b.burned_at, p.nickname,
              (SELECT COUNT(*) - 1 FROM burns f WHERE f.world_id = b.world_id AND f.played_word = b.word) AS family_count
            FROM burns b JOIN players p ON p.id = b.player_id
            WHERE b.world_id = ? AND b.word = b.played_word
            ORDER BY b.id DESC LIMIT 25');
        $stmt->execute([$worldId]);
        $recent = array_map(fn ($r) => [
            'word' => $r['word'], 'points' => (int) $r['points'], 'by' => $r['nickname'],
            'at' => (int) $r['burned_at'], 'familyCount' => (int) $r['family_count'],
        ], $stmt->fetchAll());

        $stmt = $this->db->prepare('
            SELECT p.nickname,
              COALESCE(SUM(b.points), 0) AS total,
              COALESCE(SUM(CASE WHEN b.prompt_id = ? THEN b.points END), 0) AS this_prompt,
              COUNT(CASE WHEN b.word = b.played_word THEN 1 END) AS words
            FROM players p LEFT JOIN burns b ON b.player_id = p.id
            WHERE p.world_id = ?
            GROUP BY p.id, p.nickname
            ORDER BY total DESC, words DESC, p.nickname
            LIMIT 50');
        $stmt->execute([$prompt['id'] ?? -1, $worldId]);
        $leaderboard = array_map(fn ($r) => [
            'nickname' => $r['nickname'], 'total' => (int) $r['total'],
            'thisPrompt' => (int) $r['this_prompt'], 'words' => (int) $r['words'],
        ], $stmt->fetchAll());

        $me = null;
        if ($token) {
            $stmt = $this->db->prepare('SELECT nickname FROM players WHERE world_id = ? AND token = ?');
            $stmt->execute([$worldId, $token]);
            $me = $stmt->fetchColumn() ?: null;
        }

        return [
            'world' => ['id' => $world['id'], 'name' => $world['name']],
            'me' => $me,
            'prompt' => $prompt ? [
                'type' => $prompt['type'],
                'letters' => $prompt['letters'],
                'label' => Prompts::describe($prompt['type'], $prompt['letters']),
                'remaining' => (int) $prompt['remaining'],
                'availableAtStart' => (int) $prompt['available_at_start'],
                'endsAt' => (int) $prompt['ends_at'],
            ] : null,
            'multipliers' => $mult,
            'dictionary' => ['size' => $this->dictionary->size(), 'burned' => count($this->burned($worldId))],
            'recent' => $recent,
            'leaderboard' => $leaderboard,
            'now' => $this->now(),
        ];
    }
}
