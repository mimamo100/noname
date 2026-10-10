<?php
declare(strict_types=1);

/**
 * Accounts and signing in. Everyone needs a free account to play.
 *
 * Signing in: the player enters their email, gets a 6-digit code by email, and types it in.
 * The device then stays signed in for a year, renewed whenever they play, so most players
 * only ever get one email per device. New players also choose the name shown in every world.
 */
final class Auth
{
    public const CODE_MINUTES = 15;
    public const CODE_ATTEMPTS = 5;
    public const CODES_PER_EMAIL_PER_HOUR = 3;
    /** Caps guessing: 10 codes x 5 tries a day gives about a 2% chance a year of guessing one. */
    public const CODES_PER_EMAIL_PER_DAY = 10;
    public const CODES_PER_IP_PER_HOUR = 10;
    public const SESSION_DAYS = 365;
    private const NAME_PATTERN = '/^[\p{L}\p{N}_ -]{1,20}$/u';
    private const DAY_MS = 86_400_000;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly Dictionary $dictionary,
        ?callable $clock = null,
    ) {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->clock = $clock ?? fn () => (int) floor(microtime(true) * 1000);
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function codeHash(string $email, string $code): string
    {
        return hash('sha256', "$email|$code");
    }

    // --- Codes ---

    /** Emails a sign-in code. Says the same whether or not the email has an account. */
    public function start(string $rawEmail, string $ip): void
    {
        $email = self::normaliseEmail($rawEmail);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new GameError(400, 'Please enter a valid email address');
        $count = function (string $column, string $value, int $sinceMs): int {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM login_codes WHERE $column = ? AND created_at > ?");
            $stmt->execute([$value, $this->now() - $sinceMs]);
            return (int) $stmt->fetchColumn();
        };
        if ($count('email', $email, 3_600_000) >= self::CODES_PER_EMAIL_PER_HOUR
            || $count('email', $email, self::DAY_MS) >= self::CODES_PER_EMAIL_PER_DAY
            || $count('ip', $ip, 3_600_000) >= self::CODES_PER_IP_PER_HOUR) {
            throw new GameError(429, 'Too many codes asked for. Please wait a while and try again.');
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $this->db->prepare('INSERT INTO login_codes (email, code_hash, ip, created_at, expires_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, self::codeHash($email, $code), $ip, $this->now(), $this->now() + self::CODE_MINUTES * 60_000]);
        $id = $this->db->lastInsertId();
        try {
            $this->mailer->send($email, "Your Unsaid code: $code", implode("\n", [
                "Your code for Unsaid is: $code",
                '',
                'Type it into the game to sign in. It works for ' . self::CODE_MINUTES . ' minutes.',
                '',
                "If you didn't ask for this, you can ignore this email: nobody can sign in without the code.",
            ]));
        } catch (RuntimeException $e) {
            error_log('Unsaid mail: ' . $e->getMessage());
            $this->db->prepare('DELETE FROM login_codes WHERE id = ?')->execute([$id]);
            throw new GameError(503, "Sorry, we couldn't send the email just now. Please try again later.");
        }
    }

    /**
     * Checks a code. Returns a session for an existing account. For a new email, returns
     * ['needsName' => true] until the player also sends the name they want.
     *
     * @param string[] $claimTokens player tokens from before accounts, saved on this device
     */
    public function verify(string $rawEmail, string $code, ?string $name = null, array $claimTokens = []): array
    {
        $email = self::normaliseEmail($rawEmail);
        $stmt = $this->db->prepare('SELECT * FROM login_codes WHERE email = ? AND used = 0 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['expires_at'] < $this->now()) throw new GameError(400, 'That code has expired. Please ask for a new one.');
        if ((int) $row['attempts'] >= self::CODE_ATTEMPTS) throw new GameError(400, 'Too many wrong tries. Please ask for a new code.');
        if (!hash_equals($row['code_hash'], self::codeHash($email, trim($code)))) {
            $this->db->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
            throw new GameError(400, "That code isn't right. Check the email and try again.");
        }

        $user = $this->userByEmail($email);
        if (!$user) {
            if ($name === null || trim($name) === '') return ['needsName' => true];
            $user = $this->createUser($email, $name);
        }
        $this->db->prepare('UPDATE login_codes SET used = 1 WHERE id = ?')->execute([$row['id']]);
        $this->claim((int) $user['id'], $claimTokens);
        return ['token' => $this->createSession((int) $user['id']), 'user' => self::publicUser($user)];
    }

    // --- Accounts ---

    private function userByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function createUser(string $email, string $rawName): array
    {
        $name = trim($rawName);
        if (!preg_match(self::NAME_PATTERN, $name)) throw new GameError(400, 'Names are 1–20 letters, numbers, spaces, - or _');
        if ($this->dictionary->isOffensiveName($name)) throw new GameError(400, 'Please choose a different name');
        try {
            $this->db->prepare('INSERT INTO users (email, name, created_at, last_seen_at) VALUES (?, ?, ?, ?)')
                ->execute([$email, $name, $this->now(), $this->now()]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') throw new GameError(409, 'That name is taken. Please choose another.');
            throw $e;
        }
        return $this->userByEmail($email);
    }

    private static function publicUser(array $user): array
    {
        return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
    }

    /** Links players from before accounts (identified by their old tokens) to this account. */
    private function claim(int $userId, array $tokens): void
    {
        foreach (array_slice(array_filter($tokens, 'is_string'), 0, 100) as $token) {
            $stmt = $this->db->prepare('SELECT id, world_id FROM players WHERE token = ? AND user_id IS NULL');
            $stmt->execute([$token]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$player) continue;
            $taken = $this->db->prepare('SELECT 1 FROM players WHERE world_id = ? AND user_id = ?');
            $taken->execute([$player['world_id'], $userId]);
            if ($taken->fetchColumn()) continue; // Already playing there under the account.
            $this->db->prepare('UPDATE players SET user_id = ? WHERE id = ?')->execute([$userId, $player['id']]);
        }
    }

    // --- Sessions ---

    private function createSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->prepare('INSERT INTO sessions (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([hash('sha256', $token), $userId, $this->now(), $this->now() + self::SESSION_DAYS * self::DAY_MS]);
        return $token;
    }

    /** The signed-in user for a session token, or null. Keeps active sessions from expiring. */
    public function user(?string $token): ?array
    {
        if (!$token) return null;
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare('
            SELECT u.*, s.expires_at FROM sessions s JOIN users u ON u.id = s.user_id
            WHERE s.token_hash = ? AND s.expires_at > ?');
        $stmt->execute([$hash, $this->now()]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        // Renew at most once a day: a year from the last time they played.
        $renewed = $this->now() + self::SESSION_DAYS * self::DAY_MS;
        if ($renewed - (int) $user['expires_at'] > self::DAY_MS) {
            $this->db->prepare('UPDATE sessions SET expires_at = ? WHERE token_hash = ?')->execute([$renewed, $hash]);
            $this->db->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([$this->now(), $user['id']]);
        }
        return self::publicUser($user);
    }

    public function logout(?string $token): void
    {
        if ($token) $this->db->prepare('DELETE FROM sessions WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
}
