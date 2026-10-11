<?php
declare(strict_types=1);

/**
 * Daily tidying, run by a cPanel cron job (scripts/housekeeping.php) or from the admin page:
 *
 * - sign-in codes older than two days (they hold email and network addresses, and the
 *   sign-in limits only look back one day)
 * - signed-in devices whose session has expired
 * - free misses (typos, words not on our lists) older than keep_days; misses that cost
 *   points stay, as scores include them
 * - the words and misses of worlds that finished more than keep_days ago; their final
 *   standings stay in results, which is what My games shows
 * - cached world states nobody has looked at for a day
 *
 * Deletes in batches, so the game keeps running smoothly meanwhile. Each run is recorded in
 * the meta table, and the admin page shows when it last ran.
 */
final class Housekeeping
{
    private const BATCH = 5000;
    private const CODE_DAYS = 2;
    private const DAY_MS = 86_400_000;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly int $keepDays = 30,
        private readonly ?string $cacheDir = null,
        ?callable $clock = null,
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    /** Runs every job and returns how much each one removed. */
    public function run(): array
    {
        $now = ($this->clock)();
        $keepSince = $now - $this->keepDays * self::DAY_MS;
        $done = [
            'signInCodes' => $this->deleteInBatches('DELETE FROM login_codes WHERE created_at < ?', [$now - self::CODE_DAYS * self::DAY_MS]),
            'sessions' => $this->deleteInBatches('DELETE FROM sessions WHERE expires_at < ?', [$now]),
            'freeMisses' => $this->deleteInBatches('DELETE FROM misses WHERE points = 0 AND missed_at < ?', [$keepSince]),
            'finishedWorlds' => 0,
            'words' => 0,
            'misses' => 0,
            'cacheFiles' => $this->cleanCache($now),
        ];

        // Finished worlds' detail. Their players keep their final scores, so the final
        // leaderboard can still be seen; prompts stay too, as reports point to them.
        $stmt = $this->db->prepare('
            SELECT id FROM worlds w WHERE ended_at < ?
            AND (EXISTS (SELECT 1 FROM burns WHERE world_id = w.id) OR EXISTS (SELECT 1 FROM misses WHERE world_id = w.id))');
        $stmt->execute([$keepSince]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $worldId) {
            $done['finishedWorlds']++;
            $done['words'] += $this->deleteInBatches('DELETE FROM burns WHERE world_id = ?', [$worldId]);
            $done['misses'] += $this->deleteInBatches('DELETE FROM misses WHERE world_id = ?', [$worldId]);
        }

        $this->db->prepare('REPLACE INTO meta (name, value) VALUES (?, ?)')
            ->execute(['housekeeping', json_encode(['at' => $now, 'done' => $done])]);
        return $done;
    }

    /** When housekeeping last ran and what it did, or null if it never has. */
    public static function lastRun(PDO $db): ?array
    {
        $stmt = $db->prepare('SELECT value FROM meta WHERE name = ?');
        $stmt->execute(['housekeeping']);
        $value = $stmt->fetchColumn();
        return $value === false ? null : json_decode($value, true);
    }

    private function deleteInBatches(string $sql, array $params): int
    {
        $stmt = $this->db->prepare($sql . ' LIMIT ' . self::BATCH);
        $total = 0;
        do {
            $stmt->execute($params);
            $total += $deleted = $stmt->rowCount();
        } while ($deleted === self::BATCH);
        return $total;
    }

    private function cleanCache(int $now): int
    {
        if (!$this->cacheDir || !is_dir($this->cacheDir)) return 0;
        $removed = 0;
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < intdiv($now, 1000) - 86_400 && @unlink($file)) $removed++;
        }
        return $removed;
    }
}
