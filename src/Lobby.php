<?php
declare(strict_types=1);

/**
 * Where players play beyond private worlds:
 *
 * - The big world: one world for every signed-in player, started afresh every day (or every
 *   few hours). Each round is its own world, with an id from its start time; /api/worlds/big
 *   always means the current round. Its prompts are bigger and shorter, so it moves fast.
 * - Rooms: worlds of up to 60 players, filled automatically. A room lasts until its words run
 *   out. Players who haven't guessed for a week show as away, and their seat goes to someone new.
 *
 * Settings come from the 'big_world' and 'rooms' sections of config.php.
 */
final class Lobby
{
    public const BIG_WORLD_ALIAS = 'big';

    private readonly int $roundHours;
    private readonly DateTimeZone $timezone;
    private readonly int $roundPromptMs;
    private readonly array $roundPromptAnswers;
    private readonly int $roomSize;
    private readonly int $maxRooms;
    /** @var callable(): int */
    private $clock;

    public function __construct(private readonly PDO $db, private readonly Game $game, array $config = [], ?callable $clock = null)
    {
        $big = $config['big_world'] ?? [];
        $hours = (int) ($big['round_hours'] ?? 24);
        // Rounds line up with midnight, so the hours must divide the day.
        $this->roundHours = in_array($hours, [1, 2, 3, 4, 6, 8, 12, 24], true) ? $hours : 24;
        $this->timezone = new DateTimeZone($big['timezone'] ?? 'Europe/London');
        $this->roundPromptMs = (int) (($big['prompt_minutes'] ?? 15) * 60_000);
        $this->roundPromptAnswers = $big['prompt_answers'] ?? [60, 400];
        $rooms = $config['rooms'] ?? [];
        $this->roomSize = (int) ($rooms['size'] ?? 60);
        $this->maxRooms = (int) ($rooms['max'] ?? 50);
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    // --- The big world ---

    /** The current round's start and end, in milliseconds. */
    public function currentRound(): array
    {
        $now = (new DateTimeImmutable('@' . intdiv($this->now(), 1000)))->setTimezone($this->timezone);
        $start = $now->setTime(intdiv((int) $now->format('G'), $this->roundHours) * $this->roundHours, 0);
        // By the wall clock, so a round over a clock change is an hour longer or shorter but
        // still ends on the hour the next one starts.
        $end = $start->setTime((int) $start->format('G') + $this->roundHours, 0);
        return [
            'id' => 'p' . $start->format('YmdHi'),
            'name' => 'The Big World, ' . $start->format($this->roundHours === 24 ? 'j M' : 'j M, H:i'),
            'start' => $start->getTimestamp() * 1000,
            'end' => $end->getTimestamp() * 1000,
        ];
    }

    /** The id of the big world's current round, starting it (and finishing older ones) if needed. */
    public function bigWorldId(): string
    {
        $round = $this->currentRound();
        $stmt = $this->db->prepare('SELECT 1 FROM worlds WHERE id = ?');
        $stmt->execute([$round['id']]);
        if (!$stmt->fetchColumn()) {
            try {
                $this->game->createWorld($round['name'], $round['id'], $this->roundPromptMs, 'both', null, [
                    'kind' => 'public',
                    'endsAt' => $round['end'],
                    'promptMin' => $this->roundPromptAnswers[0],
                    'promptMax' => $this->roundPromptAnswers[1],
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e; // Another request started it first.
            }
            // Earlier rounds nobody has visited since they ended.
            $stmt = $this->db->prepare("SELECT id FROM worlds WHERE kind = 'public' AND ended_at IS NULL AND id <> ?");
            $stmt->execute([$round['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $old) $this->game->finishWorld($old);
        }
        return $round['id'];
    }

    /** The last finished round's top three, for the big world's page. */
    public function lastRound(): ?array
    {
        $world = $this->db->query("
            SELECT id, name FROM worlds WHERE kind = 'public' AND ended_at IS NOT NULL
            ORDER BY ended_at DESC LIMIT 1")->fetch();
        if (!$world) return null;
        $stmt = $this->db->prepare('SELECT nickname, place, score FROM results WHERE world_id = ? AND place <= 3 ORDER BY place, nickname');
        $stmt->execute([$world['id']]);
        $winners = array_map(fn ($r) => ['nickname' => $r['nickname'], 'place' => (int) $r['place'], 'score' => (int) $r['score']], $stmt->fetchAll());
        return $winners ? ['id' => $world['id'], 'name' => $world['name'], 'winners' => $winners] : null;
    }

    // --- Rooms ---

    /** Players in the room who've guessed recently enough to hold a seat. */
    private function seatsTaken(string $roomId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM players WHERE world_id = ? AND last_active_at >= ?');
        $stmt->execute([$roomId, $this->now() - $this->game->awayAfterMs]);
        return (int) $stmt->fetchColumn();
    }

    /** The account's unfinished room, if it still has a seat there. */
    public function myRoom(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT w.id, w.name, p.last_active_at FROM players p JOIN worlds w ON w.id = p.world_id
            WHERE p.user_id = ? AND w.kind = 'room' AND w.ended_at IS NULL
            ORDER BY p.last_active_at DESC LIMIT 1");
        $stmt->execute([$userId]);
        $room = $stmt->fetch();
        if (!$room) return null;
        $active = (int) $room['last_active_at'] >= $this->now() - $this->game->awayAfterMs;
        // Away, and the room filled up meanwhile: the seat has gone.
        if (!$active && $this->seatsTaken($room['id']) >= $this->roomSize) return null;
        return ['id' => $room['id'], 'name' => $room['name']];
    }

    /**
     * Puts the account in a room: back in its own if it still has a seat, otherwise in the
     * busiest room with a free seat, otherwise a new room. Returns the room.
     */
    public function joinRoom(array $user): array
    {
        // One join at a time, so two players can't both take a room's last seat.
        if (!(int) $this->db->query("SELECT GET_LOCK('unsaid_rooms', 10)")->fetchColumn()) {
            throw new GameError(503, 'The rooms are busy. Please try again.');
        }
        try {
            $room = $this->myRoom((int) $user['id']) ?? $this->roomWithSeat() ?? $this->newRoom();
            $player = $this->game->joinAsUser($room['id'], $user);
            // Back from being away: holding a seat again.
            $this->db->prepare('UPDATE players SET last_active_at = ? WHERE id = ?')->execute([$this->now(), $player['id']]);
            return $room;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('unsaid_rooms')");
        }
    }

    /** The busiest unfinished room with a free seat, skipping rooms that are nearly out of words. */
    private function roomWithSeat(): ?array
    {
        $since = $this->now() - $this->game->awayAfterMs;
        $stmt = $this->db->prepare("
            SELECT w.id, w.name,
              (SELECT COUNT(*) FROM players p WHERE p.world_id = w.id AND p.last_active_at >= ?) AS seats,
              (SELECT COUNT(*) FROM burns b WHERE b.world_id = w.id) AS burned
            FROM worlds w WHERE w.kind = 'room' AND w.ended_at IS NULL
            ORDER BY seats DESC, w.created_at");
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll() as $room) {
            if ((int) $room['seats'] >= $this->roomSize) continue;
            // A newcomer would find little left to say once three quarters are gone.
            if ((int) $room['burned'] > 0.75 * $this->game->dictionarySize()) continue;
            return ['id' => $room['id'], 'name' => $room['name']];
        }
        return null;
    }

    private function newRoom(): array
    {
        $open = (int) $this->db->query("SELECT COUNT(*) FROM worlds WHERE kind = 'room' AND ended_at IS NULL")->fetchColumn();
        if ($open >= $this->maxRooms) {
            throw new GameError(503, 'All the rooms are full right now. Try the big world, or come back later.');
        }
        $number = 1 + (int) $this->db->query("SELECT COUNT(*) FROM worlds WHERE kind = 'room'")->fetchColumn();
        $world = $this->game->createWorld("Room $number", null, Game::DEFAULT_PROMPT_DURATION_MS, 'both', null, ['kind' => 'room']);
        return ['id' => $world['id'], 'name' => $world['name']];
    }

    /**
     * The account's player in a world it wants to play in. Rooms are joined only through
     * joinRoom(), and a player who was away needs a free seat to carry on.
     */
    public function playerFor(string $worldId, array $user): array
    {
        $world = $this->game->world($worldId);
        if (($world['kind'] ?? 'private') !== 'room') return $this->game->joinAsUser($worldId, $user);
        $player = $this->game->playerForUser($worldId, (int) $user['id'])
            ?? throw new GameError(403, 'This room is for its own players. Join a room from the home page.');
        $away = (int) $player['last_active_at'] < $this->now() - $this->game->awayAfterMs;
        if ($away && !$world['ended_at'] && $this->seatsTaken($worldId) >= $this->roomSize) {
            throw new GameError(403, 'Your seat in this room went to someone new while you were away. Join a new room from the home page.');
        }
        return $player;
    }

    // --- My games ---

    /** The account's finished games, newest first, and its personal bests. */
    public function history(int $userId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.world_id, w.name, w.kind, r.place, r.players, r.score, r.words, r.best_word, r.best_points, r.ended_at,
              r.team_name, tr.place AS team_place, tr.teams
            FROM results r JOIN worlds w ON w.id = r.world_id
            LEFT JOIN team_results tr ON tr.world_id = r.world_id AND tr.name = r.team_name
            WHERE r.user_id = ? ORDER BY r.ended_at DESC LIMIT 100');
        $stmt->execute([$userId]);
        $games = array_map(fn ($r) => [
            'worldId' => $r['world_id'], 'name' => $r['name'], 'kind' => $r['kind'],
            'place' => (int) $r['place'], 'players' => (int) $r['players'],
            'score' => (int) $r['score'], 'words' => (int) $r['words'],
            'bestWord' => $r['best_word'], 'bestPoints' => $r['best_points'] === null ? null : (int) $r['best_points'],
            'endedAt' => (int) $r['ended_at'],
            'team' => $r['team_name'] === null ? null
                : ['name' => $r['team_name'], 'place' => $r['team_place'] === null ? null : (int) $r['team_place'], 'teams' => (int) $r['teams']],
        ], $stmt->fetchAll());

        $stmt = $this->db->prepare('
            SELECT COUNT(*) AS games, MIN(place) AS best_place, MAX(score) AS best_score, MAX(words) AS most_words,
              SUM(place = 1) AS wins
            FROM results WHERE user_id = ?');
        $stmt->execute([$userId]);
        $b = $stmt->fetch();
        // Every word said, in finished and unfinished worlds alike.
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(words), 0) FROM players WHERE user_id = ?');
        $stmt->execute([$userId]);
        $best = $this->db->prepare('
            SELECT best_word, best_points FROM results WHERE user_id = ? AND best_word IS NOT NULL
            ORDER BY best_points DESC, ended_at LIMIT 1');
        $best->execute([$userId]);
        $bestWord = $best->fetch() ?: null;
        return [
            'bests' => [
                'games' => (int) $b['games'],
                'wins' => (int) $b['wins'],
                'bestPlace' => $b['best_place'] === null ? null : (int) $b['best_place'],
                'bestScore' => $b['best_score'] === null ? null : (int) $b['best_score'],
                'mostWords' => $b['most_words'] === null ? null : (int) $b['most_words'],
                'wordsSaid' => (int) $stmt->fetchColumn(),
                'bestWord' => $bestWord ? ['word' => $bestWord['best_word'], 'points' => (int) $bestWord['best_points']] : null,
            ],
            'games' => $games,
        ];
    }
}
