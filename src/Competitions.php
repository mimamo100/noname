<?php
declare(strict_types=1);

/**
 * Competitions: private worlds with teams and a fixed start and end, run by an account on the
 * Organiser plan.
 *
 * - Each team has its own invite link: opening it and saying a word joins that team. The
 *   competition's own link lets people watch.
 * - Before the start there is no prompt: the page counts down and shows the teams forming.
 * - A team's score is the total of its players' scores. Players can't change team; the
 *   organiser can move them until the start, and remove them at any time.
 * - At the end, final standings for players and teams go into My games.
 */
final class Competitions
{
    public const MAX_OPEN = 3;
    public const MIN_TEAMS = 2;
    public const MAX_TEAMS = 20;
    public const MAX_TEAM_SIZE = 50;
    /** Prompt lengths an organiser can choose, in minutes. */
    public const PACES = [5, 10, 15, 30, 60, 240, 1440];
    /** The longest a competition can run. */
    private const MAX_LENGTH_MS = 31 * 86_400_000;

    /** @var callable(): int */
    private $clock;

    public function __construct(private readonly PDO $db, private readonly Game $game, ?callable $clock = null)
    {
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    public static function canOrganise(?array $user): bool
    {
        return ($user['plan'] ?? 'free') === 'organiser';
    }

    // --- Creating ---

    /**
     * @param array{name?: string, spelling?: string, startsAt?: int, endsAt?: int, promptMinutes?: int,
     *   teams?: string[], teamSize?: ?int} $input
     */
    public function create(array $user, array $input): array
    {
        if (!self::canOrganise($user)) throw new GameError(403, 'Running competitions needs the Organiser plan.');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM worlds WHERE owner_user_id = ? AND kind = 'competition' AND ended_at IS NULL");
        $stmt->execute([$user['id']]);
        if ((int) $stmt->fetchColumn() >= self::MAX_OPEN) {
            throw new GameError(403, 'You have ' . self::MAX_OPEN . ' competitions planned or running, the most at once. End one to start another.');
        }
        [$startsAt, $endsAt] = $this->times((int) ($input['startsAt'] ?? 0), (int) ($input['endsAt'] ?? 0));
        $names = $this->teamNames($input['teams'] ?? []);
        $minutes = $this->pace($input['promptMinutes'] ?? 15);
        $world = $this->game->createWorld(
            (string) ($input['name'] ?? ''), null, $minutes * 60_000, (string) ($input['spelling'] ?? 'both'), (int) $user['id'],
            // A pack-only world's smaller prompts come first, so they win.
            PrivateWorlds::packOptions($input) + [
                'kind' => 'competition', 'startsAt' => $startsAt, 'endsAt' => $endsAt,
                'teamSize' => $this->teamSize($input['teamSize'] ?? null),
                'repeatPenalty' => !empty($input['repeatPenalty']),
                // Short prompts for a live event get more answers, so a busy room doesn't empty them at once.
                'promptMin' => $minutes <= 15 ? 30 : null, 'promptMax' => $minutes <= 15 ? 200 : null,
            ],
        );
        foreach ($names as $i => $name) $this->insertTeam($world['id'], $name, $i);
        return ['id' => $world['id'], 'name' => $world['name']];
    }

    private function times(int $startsAt, int $endsAt): array
    {
        if ($startsAt < $this->now() - 60_000) throw new GameError(400, 'The start time has passed');
        if ($endsAt <= $startsAt) throw new GameError(400, 'The end must be after the start');
        if ($endsAt - $startsAt > self::MAX_LENGTH_MS) throw new GameError(400, 'A competition can last up to 31 days');
        return [$startsAt, $endsAt];
    }

    private function pace(mixed $minutes): int
    {
        if (!in_array((int) $minutes, self::PACES, true)) throw new GameError(400, 'Prompts can last 5, 10, 15 or 30 minutes, an hour, 4 hours or a day');
        return (int) $minutes;
    }

    private function teamSize(mixed $size): ?int
    {
        if ($size === null || $size === '' || $size === 0) return null;
        $size = (int) $size;
        if ($size < 1 || $size > self::MAX_TEAM_SIZE) throw new GameError(400, 'Teams can have up to ' . self::MAX_TEAM_SIZE . ' players');
        return $size;
    }

    private function cleanTeamName(string $name): string
    {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 30);
        if ($name === '') throw new GameError(400, 'Every team needs a name');
        return $name;
    }

    /** @return string[] */
    private function teamNames(mixed $teams): array
    {
        $names = array_map(fn ($n) => $this->cleanTeamName((string) $n), array_filter((array) $teams, fn ($n) => trim((string) $n) !== ''));
        if (count($names) < self::MIN_TEAMS || count($names) > self::MAX_TEAMS) {
            throw new GameError(400, 'A competition needs ' . self::MIN_TEAMS . '–' . self::MAX_TEAMS . ' teams');
        }
        if (count(array_unique(array_map('mb_strtolower', $names))) !== count($names)) throw new GameError(400, 'Two teams have the same name');
        return array_values($names);
    }

    private function insertTeam(string $worldId, string $name, int $position): void
    {
        try {
            $this->db->prepare('INSERT INTO teams (world_id, name, invite_code, position, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$worldId, $name, Game::newInviteCode(), $position, $this->now()]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new GameError(400, "There's already a team called $name");
            throw $e;
        }
    }

    // --- Who can see and join ---

    private function isOwner(array $world, ?array $user): bool
    {
        return $user !== null && (int) ($world['owner_user_id'] ?? 0) === (int) $user['id'];
    }

    private function teamByInvite(string $worldId, ?string $invite): ?array
    {
        if ($invite === null || $invite === '') return null;
        $stmt = $this->db->prepare('SELECT * FROM teams WHERE world_id = ? AND invite_code = ?');
        $stmt->execute([$worldId, $invite]);
        return $stmt->fetch() ?: null;
    }

    /** The organiser, players, and anyone with the watching link or a team's link can see it. */
    public function checkCanView(array $world, ?array $user, ?string $invite): void
    {
        if ($this->isOwner($world, $user)) return;
        $player = $user ? $this->game->playerForUser($world['id'], (int) $user['id']) : null;
        if ($player && $player['removed']) throw new GameError(403, "You've been removed from this competition.");
        if ($player) return;
        if ($invite !== null && hash_equals((string) $world['invite_code'], $invite)) return;
        if ($this->teamByInvite($world['id'], $invite)) return;
        throw new GameError(403, 'This is a private competition. You need your team\'s invite link.');
    }

    /** The account's player, joining the team whose link was opened if it isn't in yet. */
    public function playerFor(array $world, array $user, ?string $invite): array
    {
        $player = $this->game->playerForUser($world['id'], (int) $user['id']);
        if ($player) {
            if ($player['removed']) throw new GameError(403, "You've been removed from this competition.");
            if ($player['team_id'] !== null) return $player;
        }
        $team = $this->teamByInvite($world['id'], $invite)
            ?? throw new GameError(403, "To play, open your team's invite link. Ask the organiser for it.");
        if ($world['ended_at']) throw new GameError(410, 'This competition has finished.');
        if ($world['team_size'] !== null && $this->teamCount((int) $team['id']) >= (int) $world['team_size']) {
            throw new GameError(403, "{$team['name']} is full.");
        }
        $player ??= $this->game->joinAsUser($world['id'], $user);
        $this->db->prepare('UPDATE players SET team_id = ? WHERE id = ?')->execute([$team['id'], $player['id']]);
        $this->game->changed($world['id']);
        return $this->game->playerForUser($world['id'], (int) $user['id']);
    }

    private function teamCount(int $teamId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM players WHERE team_id = ? AND removed = 0');
        $stmt->execute([$teamId]);
        return (int) $stmt->fetchColumn();
    }

    /** What this viewer sees beyond the shared state: their team and its link, or the organiser's tools. */
    public function personal(array $world, ?array $user, ?string $invite): array
    {
        $out = ['isOwner' => $this->isOwner($world, $user), 'myTeam' => null, 'invite' => null, 'joiningTeam' => null];
        $player = $user ? $this->game->playerForUser($world['id'], (int) $user['id']) : null;
        if ($player && !$player['removed'] && $player['team_id'] !== null) {
            // No team link for players: the organiser decides who gets it.
            $stmt = $this->db->prepare('SELECT name, position FROM teams WHERE id = ?');
            $stmt->execute([$player['team_id']]);
            if ($team = $stmt->fetch()) {
                $out['myTeam'] = ['name' => $team['name'], 'position' => (int) $team['position']];
                // Moved by the organiser: say so, from which team.
                if (!empty($player['moved_from']) && $player['moved_from'] !== $team['name']) $out['movedFrom'] = $player['moved_from'];
                // Opened another team's link: you stay on your own team, and the page says so.
                $other = $this->teamByInvite($world['id'], $invite);
                if ($other && (int) $other['id'] !== (int) $player['team_id']) $out['otherTeamLink'] = $other['name'];
            }
        } elseif ($team = $this->teamByInvite($world['id'], $invite)) {
            // Opened a team's link but not joined yet: saying a word joins this team.
            $out['joiningTeam'] = ['name' => $team['name'], 'position' => (int) $team['position']];
        }
        if ($out['isOwner']) {
            // So the organiser can send each team its link from the page itself.
            $stmt = $this->db->prepare('SELECT id, invite_code FROM teams WHERE world_id = ?');
            $stmt->execute([$world['id']]);
            $out['teamLinks'] = array_map('strval', array_column($stmt->fetchAll(), 'invite_code', 'id'));
        }
        return $out;
    }

    // --- Organiser controls ---

    private function ownedCompetition(string $worldId, array $user): array
    {
        $world = $this->game->world($worldId);
        if (($world['kind'] ?? '') !== 'competition' || !$this->isOwner($world, $user)) {
            throw new GameError(403, 'Only the organiser can change this competition.');
        }
        return $world;
    }

    /** A word or report sent before the start is refused, without joining a team on the way. */
    public function checkStarted(array $world): void
    {
        if (!$this->started($world)) throw new GameError(409, "The competition hasn't started yet.");
    }

    private function started(array $world): bool
    {
        return $this->now() >= (int) $world['starts_at'];
    }

    public function settings(string $worldId, array $user): array
    {
        $world = $this->ownedCompetition($worldId, $user);
        $stmt = $this->db->prepare('SELECT id, name, position, invite_code FROM teams WHERE world_id = ? ORDER BY position');
        $stmt->execute([$worldId]);
        $teams = [];
        foreach ($stmt->fetchAll() as $t) {
            $teams[(int) $t['id']] = ['id' => (int) $t['id'], 'name' => $t['name'], 'position' => (int) $t['position'],
                'invite' => $t['invite_code'], 'players' => []];
        }
        $stmt = $this->db->prepare('SELECT nickname, team_id, score, words FROM players WHERE world_id = ? AND removed = 0 ORDER BY nickname');
        $stmt->execute([$worldId]);
        foreach ($stmt->fetchAll() as $p) {
            if (isset($teams[(int) $p['team_id']])) {
                $teams[(int) $p['team_id']]['players'][] = ['nickname' => $p['nickname'], 'score' => (int) $p['score'], 'words' => (int) $p['words']];
            }
        }
        return [
            'name' => $world['name'],
            'startsAt' => (int) $world['starts_at'],
            'endsAt' => (int) $world['ends_at'],
            'promptMinutes' => intdiv((int) $world['prompt_duration_ms'], 60_000),
            'teamSize' => $world['team_size'] === null ? null : (int) $world['team_size'],
            'repeatPenalty' => (bool) ($world['repeat_penalty'] ?? false),
            'watchInvite' => $world['invite_code'],
            'started' => $this->started($world),
            'ended' => $world['ended_at'] !== null,
            'canDelete' => !$this->started($world) || $world['ended_at'] !== null,
            'teams' => array_values($teams),
        ];
    }

    /**
     * Changes the name at any time, and before the start also the times, pace and team size.
     * The end can be moved while it runs, but not into the past.
     *
     * @param array{name?: string, startsAt?: int, endsAt?: int, promptMinutes?: int, teamSize?: ?int} $input
     */
    public function update(string $worldId, array $user, array $input): array
    {
        $world = $this->ownedCompetition($worldId, $user);
        if ($world['ended_at']) throw new GameError(400, 'This competition has finished.');
        $started = $this->started($world);
        $name = array_key_exists('name', $input) ? (mb_substr(trim((string) $input['name']), 0, 40) ?: $world['name']) : $world['name'];
        $startsAt = (int) $world['starts_at'];
        $endsAt = (int) $world['ends_at'];
        if (!$started && array_key_exists('startsAt', $input)) $startsAt = (int) $input['startsAt'];
        if (array_key_exists('endsAt', $input)) $endsAt = (int) $input['endsAt'];
        if ($endsAt !== (int) $world['ends_at'] || $startsAt !== (int) $world['starts_at']) {
            if ($started) {
                if ($endsAt <= $this->now()) throw new GameError(400, 'The end must be in the future');
                if ($endsAt - $startsAt > self::MAX_LENGTH_MS) throw new GameError(400, 'A competition can last up to 31 days');
            } else {
                $this->times($startsAt, $endsAt);
            }
        }
        $pace = (int) $world['prompt_duration_ms'];
        $teamSize = $world['team_size'];
        $repeat = (int) ($world['repeat_penalty'] ?? 0);
        if (!$started) {
            if (array_key_exists('repeatPenalty', $input)) $repeat = (int) (bool) $input['repeatPenalty'];
            if (array_key_exists('promptMinutes', $input)) $pace = $this->pace($input['promptMinutes']) * 60_000;
            if (array_key_exists('teamSize', $input)) $teamSize = $this->teamSize($input['teamSize']);
        }
        $this->db->prepare('UPDATE worlds SET name = ?, starts_at = ?, ends_at = ?, prompt_duration_ms = ?, team_size = ?, repeat_penalty = ? WHERE id = ?')
            ->execute([$name, $startsAt, $endsAt, $pace, $teamSize, $repeat, $worldId]);
        // The running prompt stops at the new end.
        $this->db->prepare('UPDATE prompts SET ends_at = LEAST(ends_at, ?) WHERE world_id = ? AND ended_at IS NULL')->execute([$endsAt, $worldId]);
        $this->game->changed($worldId);
        return $this->settings($worldId, $user);
    }

    public function addTeam(string $worldId, array $user, string $name): void
    {
        $world = $this->ownedCompetition($worldId, $user);
        if ($this->started($world)) throw new GameError(400, 'Teams can only be added before the start.');
        $stmt = $this->db->prepare('SELECT COUNT(*), COALESCE(MAX(position) + 1, 0) FROM teams WHERE world_id = ?');
        $stmt->execute([$worldId]);
        [$count, $next] = $stmt->fetch(PDO::FETCH_NUM);
        if ((int) $count >= self::MAX_TEAMS) throw new GameError(400, 'A competition can have up to ' . self::MAX_TEAMS . ' teams');
        $this->insertTeam($worldId, $this->cleanTeamName($name), (int) $next);
        $this->game->changed($worldId);
    }

    private function ownedTeam(string $worldId, int $teamId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM teams WHERE id = ? AND world_id = ?');
        $stmt->execute([$teamId, $worldId]);
        return $stmt->fetch() ?: throw new GameError(404, 'No such team here.');
    }

    public function renameTeam(string $worldId, array $user, int $teamId, string $name): void
    {
        $this->ownedCompetition($worldId, $user);
        $this->ownedTeam($worldId, $teamId);
        try {
            $this->db->prepare('UPDATE teams SET name = ? WHERE id = ?')->execute([$this->cleanTeamName($name), $teamId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new GameError(400, "There's already a team called $name");
            throw $e;
        }
        $this->game->changed($worldId);
    }

    /** Takes out a team nobody has joined, before the start. */
    public function removeTeam(string $worldId, array $user, int $teamId): void
    {
        $world = $this->ownedCompetition($worldId, $user);
        $this->ownedTeam($worldId, $teamId);
        if ($this->started($world)) throw new GameError(400, 'Teams can only be removed before the start.');
        if ($this->teamCount($teamId) > 0) throw new GameError(400, 'Move its players to other teams first.');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM teams WHERE world_id = ?');
        $stmt->execute([$worldId]);
        if ((int) $stmt->fetchColumn() <= self::MIN_TEAMS) throw new GameError(400, 'A competition needs at least ' . self::MIN_TEAMS . ' teams');
        $this->db->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
        $this->game->changed($worldId);
    }

    /** A new link for one team (the old one stops working), or for watching if no team is given. */
    public function newInvite(string $worldId, array $user, ?int $teamId): string
    {
        $this->ownedCompetition($worldId, $user);
        $code = Game::newInviteCode();
        if ($teamId === null) {
            $this->db->prepare('UPDATE worlds SET invite_code = ? WHERE id = ?')->execute([$code, $worldId]);
        } else {
            $this->ownedTeam($worldId, $teamId);
            $this->db->prepare('UPDATE teams SET invite_code = ? WHERE id = ?')->execute([$code, $teamId]);
        }
        return $code;
    }

    /** Moves a player to another team, before the start (the team size limit doesn't apply to the organiser). */
    public function movePlayer(string $worldId, array $user, string $nickname, int $teamId): void
    {
        $world = $this->ownedCompetition($worldId, $user);
        if ($this->started($world)) throw new GameError(400, 'Players can only change team before the start.');
        $this->ownedTeam($worldId, $teamId);
        // Note the team they were on, so their page can tell them they've been moved.
        $stmt = $this->db->prepare('
            UPDATE players p LEFT JOIN teams t ON t.id = p.team_id
            SET p.moved_from = COALESCE(p.moved_from, t.name), p.team_id = ?
            WHERE p.world_id = ? AND p.nickname = ? AND p.removed = 0 AND (p.team_id IS NULL OR p.team_id <> ?)');
        $stmt->execute([$teamId, $worldId, $nickname, $teamId]);
        if (!$stmt->rowCount()) {
            $exists = $this->db->prepare('SELECT 1 FROM players WHERE world_id = ? AND nickname = ? AND removed = 0');
            $exists->execute([$worldId, $nickname]);
            if (!$exists->fetchColumn()) throw new GameError(404, 'No player of that name here.');
            return; // Already on that team.
        }
        $this->game->changed($worldId);
    }

    public function removePlayer(string $worldId, array $user, string $nickname): void
    {
        $this->ownedCompetition($worldId, $user);
        $stmt = $this->db->prepare('UPDATE players SET removed = 1 WHERE world_id = ? AND nickname = ? AND removed = 0');
        $stmt->execute([$worldId, $nickname]);
        if (!$stmt->rowCount()) throw new GameError(404, 'No player of that name here.');
        $this->game->changed($worldId);
    }

    /** Ends it now, early: final standings as at the normal end. */
    public function end(string $worldId, array $user): void
    {
        $world = $this->ownedCompetition($worldId, $user);
        if ($world['ended_at'] !== null) throw new GameError(400, 'This competition has already finished.');
        $this->game->finishWorld($worldId);
    }

    /** Deletes it: before it starts, or once it has finished. */
    public function delete(string $worldId, array $user): void
    {
        $world = $this->ownedCompetition($worldId, $user);
        if ($this->started($world) && $world['ended_at'] === null) throw new GameError(409, 'End the competition before deleting it.');
        $this->db->beginTransaction();
        try {
            foreach (['team_results', 'results', 'misses', 'burns', 'reports', 'prompts', 'players', 'teams'] as $table) {
                $this->db->prepare("DELETE FROM $table WHERE world_id = ?")->execute([$worldId]);
            }
            $this->db->prepare('DELETE FROM worlds WHERE id = ?')->execute([$worldId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
