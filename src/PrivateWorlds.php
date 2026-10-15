<?php
declare(strict_types=1);

/**
 * Private worlds: created by accounts on a paid plan, seen and joined only through an invite
 * link, and run by their owner (rename, pace, player limit, close, new link, remove a player,
 * end, delete). Also "My worlds", the list of worlds an account is in.
 *
 * Plans: 'free' (play and join), 'host' (also create private worlds), 'organiser' (also run
 * competitions). Until payments are added, the admin sets plans on the admin page.
 */
final class PrivateWorlds
{
    public const PLANS = ['free', 'host', 'organiser'];
    /** Open private worlds an account can own at once. */
    public const MAX_OPEN = 3;
    /** Prompt lengths an owner can choose, in hours. */
    public const PACES = [1, 4, 24];
    public const MAX_PLAYERS = 500;

    public function __construct(private readonly PDO $db, private readonly Game $game)
    {
    }

    /** The same, for a game with another dictionary: a room playing a word pack uses the pack's. */
    public function withGame(Game $game): self
    {
        return new self($this->db, $game);
    }

    public static function canHost(?array $user): bool
    {
        return in_array($user['plan'] ?? 'free', ['host', 'organiser'], true);
    }

    // --- Creating ---

    /** @param array{name?: string, spelling?: string, promptHours?: int, maxPlayers?: ?int} $input */
    public function create(array $user, array $input): array
    {
        if (!self::canHost($user)) throw new GameError(403, 'Creating private rooms needs the Host plan.');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM worlds WHERE owner_user_id = ? AND kind = 'private' AND ended_at IS NULL");
        $stmt->execute([$user['id']]);
        if ((int) $stmt->fetchColumn() >= self::MAX_OPEN) {
            throw new GameError(403, 'You have ' . self::MAX_OPEN . ' open private rooms, the most at once. End one to start another.');
        }
        $world = $this->game->createWorld(
            (string) ($input['name'] ?? ''),
            null,
            $this->paceMs($input['promptHours'] ?? 24),
            (string) ($input['spelling'] ?? 'both'),
            (int) $user['id'],
            ['kind' => 'private', 'maxPlayers' => $this->maxPlayers($input['maxPlayers'] ?? null), 'repeatPenalty' => !empty($input['repeatPenalty'])]
                + self::packOptions($input),
        );
        return ['id' => $world['id'], 'name' => $world['name'], 'invite' => $world['invite_code']];
    }

    /**
     * World options for a word pack, if one was chosen (the caller has checked the account may
     * use it). A pack-only world is small, so its prompts are too.
     */
    public static function packOptions(array $input): array
    {
        if (empty($input['pack'])) return [];
        $mode = (string) ($input['packMode'] ?? 'only');
        if (!in_array($mode, Packs::MODES, true)) throw new GameError(400, 'Choose the pack only, or the pack with everyday words');
        return ['pack' => (string) $input['pack'], 'packMode' => $mode] + ($mode === 'only' ? ['promptMin' => 5, 'promptMax' => 40] : []);
    }

    private function paceMs(mixed $hours): int
    {
        if (!in_array((int) $hours, self::PACES, true)) throw new GameError(400, 'Prompts can last 1, 4 or 24 hours');
        return (int) $hours * 3_600_000;
    }

    private function maxPlayers(mixed $max): ?int
    {
        if ($max === null || $max === '' || $max === 0) return null;
        $max = (int) $max;
        if ($max < 2 || $max > self::MAX_PLAYERS) throw new GameError(400, 'The player limit must be between 2 and ' . self::MAX_PLAYERS);
        return $max;
    }

    // --- Who can see and join ---

    private function isOwner(array $world, ?array $user): bool
    {
        return $user !== null && (int) ($world['owner_user_id'] ?? 0) === (int) $user['id'];
    }

    private function validInvite(array $world, ?string $invite): bool
    {
        // Every private world has a code; one without would be open to anyone with the address.
        return $world['invite_code'] === null || ($invite !== null && hash_equals($world['invite_code'], $invite));
    }

    /**
     * Whether this viewer may see a world: anyone for the big world and rooms; for a private
     * world, its owner, its players, and anyone with its current invite link.
     */
    public function checkCanView(array $world, ?array $user, ?string $invite): void
    {
        if (($world['kind'] ?? 'private') !== 'private' || $this->isOwner($world, $user)) return;
        $player = $user ? $this->game->playerForUser($world['id'], (int) $user['id']) : null;
        if ($player && $player['removed']) throw new GameError(403, "You've been removed from this room.");
        if ($player || $this->validInvite($world, $invite)) return;
        if ($invite !== null && $invite !== '') {
            throw new GameError(403, "This invite link doesn't work any more: the room's owner may have made a new one. Ask one of its players for the latest link.");
        }
        throw new GameError(403, 'This is a private room. You need an invite link from one of its players.');
    }

    /** The account's player in a private world, joining it if the invite link, limit and owner allow. */
    public function playerFor(array $world, array $user, ?string $invite): array
    {
        $player = $this->game->playerForUser($world['id'], (int) $user['id']);
        if ($player) {
            if ($player['removed']) throw new GameError(403, "You've been removed from this room.");
            return $player;
        }
        if (!$this->isOwner($world, $user)) {
            if (!$this->validInvite($world, $invite)) {
                throw new GameError(403, 'This invite link has been replaced. Ask one of the players for the new one.');
            }
            if ($world['closed']) throw new GameError(403, 'This room is closed to new players.');
            if ($world['max_players'] !== null && $this->playerCount($world['id']) >= (int) $world['max_players']) {
                throw new GameError(403, 'This room is full.');
            }
        }
        return $this->game->joinAsUser($world['id'], $user);
    }

    private function playerCount(string $worldId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM players WHERE world_id = ? AND removed = 0');
        $stmt->execute([$worldId]);
        return (int) $stmt->fetchColumn();
    }

    /** What a private world's own players see beyond the shared state: the link, and owner tools. */
    public function personal(array $world, ?array $user): array
    {
        if (($world['kind'] ?? 'private') !== 'private' || !$user) return [];
        $owner = $this->isOwner($world, $user);
        $player = $this->game->playerForUser($world['id'], (int) $user['id']);
        $member = $owner || ($player && !$player['removed']);
        return [
            'isOwner' => $owner,
            // Players can pass the link on, unless the world is closed.
            'invite' => $member && ($owner || !$world['closed']) ? $world['invite_code'] : null,
        ];
    }

    // --- Owner controls ---

    private function ownedWorld(string $worldId, array $user): array
    {
        $world = $this->game->world($worldId);
        if (($world['kind'] ?? '') !== 'private' || !$this->isOwner($world, $user)) {
            throw new GameError(403, 'Only the owner can change this room.');
        }
        return $world;
    }

    public function settings(string $worldId, array $user): array
    {
        $world = $this->ownedWorld($worldId, $user);
        $stmt = $this->db->prepare('
            SELECT nickname, score, words, last_active_at, user_id FROM players
            WHERE world_id = ? AND removed = 0 ORDER BY nickname');
        $stmt->execute([$worldId]);
        $players = array_map(fn ($p) => [
            'nickname' => $p['nickname'], 'score' => (int) $p['score'], 'words' => (int) $p['words'],
            'lastActive' => (int) $p['last_active_at'], 'isYou' => (int) $p['user_id'] === (int) $user['id'],
        ], $stmt->fetchAll());
        return [
            'name' => $world['name'],
            'promptHours' => intdiv((int) $world['prompt_duration_ms'], 3_600_000),
            'maxPlayers' => $world['max_players'] === null ? null : (int) $world['max_players'],
            'closed' => (bool) $world['closed'],
            'repeatPenalty' => (bool) ($world['repeat_penalty'] ?? false),
            'invite' => $world['invite_code'],
            'ended' => $world['ended_at'] !== null,
            'canDelete' => $this->canDelete($world, $user),
            'players' => $players,
        ];
    }

    /** @param array{name?: string, promptHours?: int, maxPlayers?: ?int, closed?: bool} $input */
    public function update(string $worldId, array $user, array $input): array
    {
        $world = $this->ownedWorld($worldId, $user);
        $name = array_key_exists('name', $input) ? (mb_substr(trim((string) $input['name']), 0, 40) ?: $world['name']) : $world['name'];
        $pace = array_key_exists('promptHours', $input) ? $this->paceMs($input['promptHours']) : (int) $world['prompt_duration_ms'];
        $max = array_key_exists('maxPlayers', $input) ? $this->maxPlayers($input['maxPlayers']) : $world['max_players'];
        $closed = array_key_exists('closed', $input) ? (int) (bool) $input['closed'] : (int) $world['closed'];
        $repeat = array_key_exists('repeatPenalty', $input) ? (int) (bool) $input['repeatPenalty'] : (int) ($world['repeat_penalty'] ?? 0);
        $this->db->prepare('UPDATE worlds SET name = ?, prompt_duration_ms = ?, max_players = ?, closed = ?, repeat_penalty = ? WHERE id = ?')
            ->execute([$name, $pace, $max, $closed, $repeat, $worldId]);
        $this->game->changed($worldId);
        return $this->settings($worldId, $user);
    }

    /** A new invite link; the old one stops working. */
    public function newInvite(string $worldId, array $user): string
    {
        $this->ownedWorld($worldId, $user);
        $code = Game::newInviteCode();
        $this->db->prepare('UPDATE worlds SET invite_code = ? WHERE id = ?')->execute([$code, $worldId]);
        return $code;
    }

    /** Takes a player out: off the leaderboards, and unable to see or rejoin the world. Their words stay used. */
    public function removePlayer(string $worldId, array $user, string $nickname): void
    {
        $this->ownedWorld($worldId, $user);
        $stmt = $this->db->prepare('SELECT id, user_id FROM players WHERE world_id = ? AND nickname = ? AND removed = 0');
        $stmt->execute([$worldId, $nickname]);
        $player = $stmt->fetch() ?: throw new GameError(404, 'No player of that name here.');
        if ((int) $player['user_id'] === (int) $user['id']) throw new GameError(400, "You can't remove yourself from your own room.");
        $this->db->prepare('UPDATE players SET removed = 1 WHERE id = ?')->execute([$player['id']]);
        $this->game->changed($worldId);
    }

    public function end(string $worldId, array $user): void
    {
        $world = $this->ownedWorld($worldId, $user);
        if ($world['ended_at'] !== null) throw new GameError(400, 'This room has already ended.');
        $this->game->finishWorld($worldId);
    }

    private function canDelete(array $world, array $user): bool
    {
        if ($world['ended_at'] !== null) return true;
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM players WHERE world_id = ? AND (user_id IS NULL OR user_id <> ?)');
        $stmt->execute([$world['id'], $user['id']]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Deletes the world and everything in it: only once it has ended, or if nobody else has joined. */
    public function delete(string $worldId, array $user): void
    {
        $world = $this->ownedWorld($worldId, $user);
        if (!$this->canDelete($world, $user)) throw new GameError(409, 'Other players have joined, so end the room before deleting it.');
        $this->db->beginTransaction();
        try {
            foreach (['results', 'misses', 'burns', 'reports', 'prompts', 'players'] as $table) {
                $this->db->prepare("DELETE FROM $table WHERE world_id = ?")->execute([$worldId]);
            }
            $this->db->prepare('DELETE FROM worlds WHERE id = ?')->execute([$worldId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // --- My worlds ---

    /**
     * The rooms, private worlds and competitions the account is in (and the private worlds and
     * competitions it owns), unfinished ones first, most recently played first. Finished worlds
     * are in My games, except ones the account owns, which it may want to delete.
     */
    public function myWorlds(array $user): array
    {
        $stmt = $this->db->prepare("
            SELECT w.*, p.score, p.words, t.name AS team_name FROM players p JOIN worlds w ON w.id = p.world_id
            LEFT JOIN teams t ON t.id = p.team_id
            WHERE p.user_id = ? AND p.removed = 0 AND p.left_at IS NULL AND w.kind IN ('room', 'private', 'competition') AND w.ended_at IS NULL");
        $stmt->execute([$user['id']]);
        $worlds = [];
        foreach ($stmt->fetchAll() as $w) $worlds[$w['id']] = $w;
        $stmt = $this->db->prepare("SELECT w.*, NULL AS score, NULL AS words, NULL AS team_name FROM worlds w WHERE owner_user_id = ? AND kind IN ('private', 'competition')");
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll() as $w) {
            // Owned worlds not already listed: ones the owner hasn't played, and ended ones (their
            // score is in My games).
            if (!isset($worlds[$w['id']])) $worlds[$w['id']] = $w;
        }

        $players = $this->db->prepare('SELECT COUNT(*) FROM players WHERE world_id = ? AND removed = 0');
        // When anyone last said a word there: joining or a wrong guess isn't a word.
        $burned = $this->db->prepare('SELECT COUNT(*), COALESCE(MAX(burned_at), 0) FROM burns WHERE world_id = ?');
        // The account's place among those who've played, as on the leaderboard (shared on equal scores).
        $ahead = $this->db->prepare('SELECT COUNT(*) FROM players WHERE world_id = ? AND removed = 0 AND (words > 0 OR score <> 0) AND score > ?');
        $teamResults = $this->db->prepare('SELECT name, place, score FROM team_results WHERE world_id = ? ORDER BY place, name LIMIT 3');
        $out = [];
        foreach ($worlds as $w) {
            $players->execute([$w['id']]);
            $count = $players->fetchColumn();
            $burned->execute([$w['id']]);
            [$burnedCount, $lastPlayed] = $burned->fetch(PDO::FETCH_NUM);
            $out[] = [
                'id' => $w['id'], 'name' => $w['name'], 'kind' => $w['kind'],
                'isOwner' => (int) $w['owner_user_id'] === (int) $user['id'],
                'ended' => $w['ended_at'] !== null,
                'score' => $w['score'] === null ? null : (int) $w['score'],
                'place' => $w['score'] === null || ((int) $w['words'] === 0 && (int) $w['score'] === 0) ? null
                    : ($ahead->execute([$w['id'], (int) $w['score']]) ? (int) $ahead->fetchColumn() + 1 : null),
                'team' => $w['team_name'] ?? null,
                // An ended competition's final team standings (the top three), for its card.
                'teams' => $w['kind'] === 'competition' && $w['ended_at'] !== null && $teamResults->execute([$w['id']])
                    ? array_map(fn ($t) => ['name' => $t['name'], 'place' => (int) $t['place'], 'score' => (int) $t['score']], $teamResults->fetchAll(PDO::FETCH_ASSOC))
                    : null,
                'words' => $w['words'] === null ? null : (int) $w['words'],
                'players' => (int) $count,
                'lastPlayed' => (int) $lastPlayed ?: null,
                'burned' => (int) $burnedCount,
                'left' => max(0, 1 - $burnedCount / $this->game->dictionarySize()),
                'pack' => $w['pack'] ?? null,
                'packMode' => $w['pack_mode'] ?? null,
                'startsAt' => isset($w['starts_at']) ? (int) $w['starts_at'] : null,
                'endsAt' => isset($w['ends_at']) ? (int) $w['ends_at'] : null,
            ];
        }
        usort($out, fn ($a, $b) => [$a['ended'], -($a['lastPlayed'] ?? 0)] <=> [$b['ended'], -($b['lastPlayed'] ?? 0)]);
        return $out;
    }
}
