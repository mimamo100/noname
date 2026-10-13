<?php
declare(strict_types=1);

/**
 * Streaks: how many days in a row an account has said a word, anywhere. A day runs from
 * midnight to midnight in the big world's timezone, so it matches the big world's rounds.
 * Kept on the account (streak, streak_best, streak_day), as the words themselves are
 * tidied away a month after a world finishes.
 */
final class Streaks
{
    private readonly DateTimeZone $timezone;
    /** @var callable(): int */
    private $clock;

    public function __construct(private readonly PDO $db, string $timezone = 'Europe/London', ?callable $clock = null)
    {
        $this->timezone = new DateTimeZone($timezone);
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    /** Today's date, such as "2026-10-12"; or another day, counted from today. */
    public function day(int $offset = 0): string
    {
        $now = (new DateTimeImmutable('@' . intdiv(($this->clock)(), 1000)))->setTimezone($this->timezone);
        return $now->modify("$offset days")->format('Y-m-d');
    }

    /** The hour of the day (0-23), in the same timezone. */
    public function hour(): int
    {
        return (int) (new DateTimeImmutable('@' . intdiv(($this->clock)(), 1000)))->setTimezone($this->timezone)->format('G');
    }

    /**
     * Records that the account said a word. Returns the streak when this was its first word
     * today (one more than yesterday's, or 1 after a gap), and null otherwise.
     */
    public function played(int $userId): ?int
    {
        // MySQL sets columns left to right, so streak_best sees the new streak.
        $stmt = $this->db->prepare('
            UPDATE users SET streak = IF(streak_day = ?, streak + 1, 1), streak_best = GREATEST(streak_best, streak), streak_day = ?
            WHERE id = ? AND (streak_day IS NULL OR streak_day <> ?)');
        $stmt->execute([$this->day(-1), $this->day(), $userId, $this->day()]);
        if ($stmt->rowCount() === 0) return null;
        $stmt = $this->db->prepare('SELECT streak FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /** The current streak (0 once a day is missed), the best ever, and whether today counts yet. */
    public function of(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT streak, streak_best, streak_day FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['streak' => 0, 'streak_best' => 0, 'streak_day' => null];
        $alive = in_array($row['streak_day'], [$this->day(), $this->day(-1)], true);
        return [
            'streak' => $alive ? (int) $row['streak'] : 0,
            'bestStreak' => (int) $row['streak_best'],
            'playedToday' => $row['streak_day'] === $this->day(),
        ];
    }
}
