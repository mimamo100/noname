<?php
declare(strict_types=1);

/**
 * Keeps each world's shared state in a small file for a couple of seconds, so a hundred
 * people watching a world cost one database run every couple of seconds, not a hundred.
 *
 * Each copy is tagged with the world's version number, which goes up whenever a word is
 * said, so a new word shows straight away. The time limit covers changes that don't touch
 * the version, such as a prompt running out of time or an admin accepting a word.
 */
final class StateCache
{
    public function __construct(private readonly string $dir, private readonly float $seconds = 2.0)
    {
    }

    /** A cache in $dir (created if needed), or null if it can't be written there. */
    public static function open(string $dir, float $seconds = 2.0): ?self
    {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return is_dir($dir) && is_writable($dir) ? new self($dir, $seconds) : null;
    }

    /** The cached state for $key at $version, or a fresh one from $compute. */
    public function get(string $key, int $version, callable $compute): array
    {
        $file = $this->dir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
        $entry = $this->read($file);
        if ($this->fresh($entry, $version)) return $entry['data'];

        // One request rebuilds it while others wait, so a busy world isn't worked out many times at once.
        $lock = @fopen("$file.lock", 'c');
        if (!$lock) return $compute();
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                // Someone else is rebuilding: their result will be the same, so wait for it.
                flock($lock, LOCK_EX);
                $entry = $this->read($file);
                if ($this->fresh($entry, $version)) return $entry['data'];
            }
            $data = $compute();
            $tmp = "$file." . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['version' => $version, 'at' => microtime(true), 'data' => $data])) !== false) {
                @rename($tmp, $file) || @unlink($tmp); // Readers never see a half-written file.
            }
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $file): ?array
    {
        $raw = @file_get_contents($file);
        $entry = $raw === false ? null : json_decode($raw, true);
        return is_array($entry) ? $entry : null;
    }

    private function fresh(?array $entry, int $version): bool
    {
        return $entry !== null
            && ($entry['version'] ?? null) === $version
            && microtime(true) - ($entry['at'] ?? 0) < $this->seconds;
    }
}
