<?php
declare(strict_types=1);

/**
 * The daily email, for accounts that ask for it: the big world has started afresh, with the
 * account's streak and how it did in the last round. Sent by the daily housekeeping job
 * (scripts/housekeeping.php), at most once a day, and not to anyone who has already played
 * today. Every email has a link that stops them, without signing in.
 */
final class Reminders
{
    public function __construct(
        private readonly PDO $db,
        private readonly Streaks $streaks,
    ) {
    }

    /** Turns the daily email on or off for an account. */
    public function set(int $userId, bool $on): void
    {
        $this->db->prepare('UPDATE users SET reminders = ?, reminder_token = COALESCE(reminder_token, ?) WHERE id = ?')
            ->execute([$on ? 1 : 0, bin2hex(random_bytes(16)), $userId]);
    }

    /** Turns them off from the link in an email. False if the link isn't one of ours. */
    public function stop(string $token): bool
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) return false;
        $stmt = $this->db->prepare('SELECT id FROM users WHERE reminder_token = ?');
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if ($id === false) return false;
        $this->set((int) $id, false);
        return true;
    }

    /**
     * Sends today's emails, up to $limit (the mail server's limits come first; anyone left
     * over gets theirs tomorrow). Returns how many were sent.
     */
    public function send(Mailer $mailer, string $siteUrl, int $limit = 300): int
    {
        $today = $this->streaks->day();
        $stmt = $this->db->prepare("
            SELECT id, email, name, reminder_token FROM users
            WHERE reminders = 1 AND (reminder_day IS NULL OR reminder_day <> ?) AND (streak_day IS NULL OR streak_day <> ?)
            ORDER BY id LIMIT $limit");
        $stmt->execute([$today, $today]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$users) return 0;

        $lastRound = $this->db->query("SELECT id FROM worlds WHERE kind = 'public' AND ended_at IS NOT NULL ORDER BY ended_at DESC LIMIT 1")->fetchColumn();
        $result = $this->db->prepare('SELECT place, players, score FROM results WHERE world_id = ? AND user_id = ?');
        $sent = $this->db->prepare('UPDATE users SET reminder_day = ? WHERE id = ?');
        $siteUrl = rtrim($siteUrl, '/');
        $count = 0;
        foreach ($users as $u) {
            $lines = ["Hi {$u['name']},", '', 'The big world has started afresh: every word is unsaid again, and everyone starts level.'];
            $streak = $this->streaks->of((int) $u['id'])['streak'];
            if ($streak > 1) $lines[] = "You've played $streak days in a row. Say a word today to keep it going.";
            if ($lastRound) {
                $result->execute([$lastRound, $u['id']]);
                if ($r = $result->fetch(PDO::FETCH_ASSOC)) {
                    $lines[] = "Last round you came " . self::ordinal((int) $r['place']) . " of {$r['players']} with {$r['score']} points.";
                }
            }
            array_push($lines, '', "Play: $siteUrl/", '', '--', "You asked for this daily email. To stop it: $siteUrl/?stop-emails={$u['reminder_token']}");
            try {
                $mailer->send($u['email'], 'Unsaid: a new day of words', implode("\n", $lines) . "\n");
            } catch (RuntimeException $e) {
                error_log('Unsaid reminder: ' . $e->getMessage());
                break; // The mail server is refusing: try again tomorrow.
            }
            $sent->execute([$today, $u['id']]);
            $count++;
        }
        return $count;
    }

    private static function ordinal(int $n): string
    {
        $v = $n % 100;
        return $n . (($v >= 11 && $v <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th'));
    }
}
