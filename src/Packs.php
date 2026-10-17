<?php
declare(strict_types=1);

/**
 * Word packs: themed vocabularies (Science, Law, Music...) for private worlds and competitions.
 *
 * - Curated packs are kept by hand in data/packs/<id>/ (pack.txt and one file per category,
 *   much like data/categories/, with an answer's forms separated by commas) and built into
 *   data/packs/<id>.php by scripts/build-dictionary.php. Hosts and organisers can use them.
 *   They may list blocked words they need, playable only in their worlds.
 * - Custom packs are written by organisers on the site, kept in the packs table, and built
 *   when used (cached in the cache folder). Only their owner can use them.
 *
 * A world plays a pack in one of two modes: 'only' (the pack's words are the whole dictionary)
 * or 'mixed' (the everyday dictionary plus the pack's words, with prompts from the pack's
 * categories). A pack's categories have ids like "science.element", so they never clash with
 * the everyday ones.
 */
final class Packs
{
    public const MODES = ['only', 'mixed'];
    public const MAX_WORDS = 5000;
    public const MIN_WORDS = 30;

    /** @var array<string, array> pack id => built pack, for this request */
    private array $built = [];

    public function __construct(private readonly PDO $db, private readonly ?string $cacheDir = null)
    {
    }

    // --- Building a pack from its lists ---

    /**
     * Builds a pack from its categories. Each line is one answer, listed with any other forms
     * of it ("nucleus, nuclei"); forms of words the everyday dictionary knows come along too
     * ("acid" brings "acids"). A form can be a phrase ("black hole"): it's played joined
     * ("blackhole") and shown as written. Returns the words (everyday ones first, most common
     * first, then the rest), the families, the categories, how phrases are written (joined word
     * => written form), the blocked words the pack lists, and any problems found.
     *
     * Blocked words are refused, except in curated packs: the law pack needs its crimes, and
     * they're playable only in its worlds (see Dictionary::onlyPack and withPack).
     *
     * @param array<string, array{label: string, words: string[][], also?: string[][]}> $groups
     *   category id => label and lines (each line an array of forms, as written)
     * @param string[] $leaveOut everyday forms not to bring along ("gassing" with "gas"), from a
     *   curated pack's "leave out:" line; a form the pack lists itself is kept
     */
    public static function build(array $groups, Dictionary $everyday, string $prefix, bool $curated = false, array $leaveOut = []): array
    {
        $leaveOut = array_fill_keys(array_map([Dictionary::class, 'joined'], $leaveOut), true);
        $leftOut = [];
        $problems = [];
        $families = [];   // Groups of words that are one answer.
        $categories = [];
        $display = [];
        $blocked = [];
        $unknown = []; // Single words the everyday dictionary doesn't know: typos, or specialist words.
        foreach ($groups as $id => $group) {
            $category = ['label' => $group['label'], 'words' => [], 'also' => [], 'answers' => 0]
                + (!empty($group['note']) ? ['note' => $group['note']] : []);
            foreach (['words', 'also'] as $list) {
                foreach ($group[$list] ?? [] as $forms) {
                    $kept = [];
                    foreach ($forms as $written) {
                        $entry = Dictionary::entry($written);
                        if ($entry === null) {
                            $problems[] = "“{$written}” isn't a word of 3–30 letters a to z";
                            continue;
                        }
                        [$form, $written] = $entry;
                        if ($everyday->isBlocked($form)) {
                            if (!$curated) {
                                $problems[] = "“{$written}” is on the blocklist";
                                continue;
                            }
                            $blocked[$form] = true;
                        }
                        $kept[] = $form;
                        if ($written !== $form) $display[$form] ??= $written;
                        elseif (!$everyday->has($form)) $unknown[$written] = true;
                    }
                    if (!$kept) continue;
                    $family = $kept;
                    foreach ($kept as $form) {
                        foreach ($everyday->family($form) as $w) {
                            if (isset($leaveOut[$w]) && !in_array($w, $kept, true)) $leftOut[$w] = true;
                            else $family[] = $w;
                        }
                    }
                    $families[] = array_values(array_unique($family));
                    foreach ($kept as $form) $category[$list][$form] = true;
                    if ($list === 'words') $category['answers']++; // One answer, however many forms it's listed with.
                }
            }
            $category['words'] = array_keys($category['words']);
            $category['also'] = array_values(array_diff(array_keys($category['also']), $category['words']));
            $categories["$prefix.$id"] = $category;
        }

        foreach (array_diff_key($leaveOut, $leftOut) as $w => $_) $problems[] = "“{$w}” (leave out) isn't an everyday form of any of the pack's words";

        // Join families that share a word.
        $root = [];
        $find = function (string $w) use (&$root, &$find): string {
            return $root[$w] === $w ? $w : ($root[$w] = $find($root[$w]));
        };
        foreach ($families as $family) {
            foreach ($family as $w) $root[$w] ??= $w;
            foreach (array_slice($family, 1) as $w) $root[$find($w)] = $find($family[0]);
        }
        $grouped = [];
        foreach (array_keys($root) as $w) $grouped[$find($w)][] = $w;

        $words = array_keys($root);
        usort($words, function (string $a, string $b) use ($everyday): int {
            $ka = $everyday->has($a) ? $everyday->rarity($a) : 2;
            $kb = $everyday->has($b) ? $everyday->rarity($b) : 2;
            return [$ka, $a] <=> [$kb, $b];
        });
        return [
            'words' => $words,
            'families' => array_values(array_filter($grouped, fn ($f) => count($f) > 1)),
            'categories' => $categories,
            'display' => $display,
            'blocked' => array_keys($blocked),
            'problems' => array_values(array_unique($problems)),
            'unknown' => array_keys($unknown),
        ];
    }

    /**
     * An answer's forms from one line of a word list, as written, separated by commas
     * ("black hole, black holes"); a line without a comma is one form ("egg cup"). Packs
     * written before that rule ($splitSpaces) split such a line on spaces ("quark quarks").
     *
     * @return string[]
     */
    public static function forms(string $line, bool $splitSpaces = false): array
    {
        $line = mb_strtolower($line);
        $parts = !$splitSpaces || str_contains($line, ',') ? explode(',', $line) : preg_split('/\s+/', $line);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }

    /** An old pack's text (format 1) written the current way: a line's spaces become commas unless it has one. */
    public static function convertOldText(string $text): string
    {
        return implode("\n\n", array_map(function (string $block) {
            $lines = preg_split('/\R/', trim($block));
            foreach ($lines as $i => $line) {
                if ($i > 0 && !str_contains($line, ',')) $lines[$i] = implode(', ', preg_split('/\s+/', trim($line)));
            }
            return implode("\n", $lines);
        }, preg_split('/\R\s*\R/', trim($text))));
    }

    /**
     * Reads one of a curated pack's category files (data/packs/<pack>/<id>.txt): its label, and
     * its lines of forms before and after "also:".
     *
     * @param string[] $lines non-empty lines, comments removed
     * @return array{label: ?string, note: ?string, words: string[][], also: string[][]}
     */
    public static function readCuratedFile(array $lines): array
    {
        $group = ['label' => null, 'note' => null, 'words' => [], 'also' => []];
        $list = 'words';
        foreach ($lines as $line) {
            if (str_starts_with($line, 'label:')) $group['label'] = trim(substr($line, 6));
            // "About this list", as in data/categories: what the list counts and what it doesn't.
            elseif (str_starts_with($line, 'note:')) $group['note'] = trim(substr($line, 5));
            elseif ($line === 'also:') $list = 'also';
            elseif ($forms = self::forms($line)) $group[$list][] = $forms;
        }
        return $group;
    }

    /**
     * Reads a custom pack's text: groups separated by blank lines, each a label line ("A planet")
     * then one answer per line, with any other forms of it on the same line (see forms()).
     *
     * @return array<string, array{label: string, words: string[][]}>
     */
    public static function parseText(string $text, bool $splitSpaces = false): array
    {
        $groups = [];
        $n = 0;
        foreach (preg_split('/\R\s*\R/', trim($text)) as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block)), 'strlen'));
            if (!$lines) continue;
            $label = mb_substr(array_shift($lines), 0, 60);
            $groups['c' . ++$n] = [
                'label' => $label,
                'words' => array_values(array_filter(array_map(fn ($l) => self::forms($l, $splitSpaces), $lines))),
            ];
        }
        return $groups;
    }

    // --- Curated packs ---

    /** @return array<string, array{name: string, description: string, words: int}> curated pack id => summary */
    public static function curated(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__) . '/data/packs/*.php') ?: [] as $path) {
            $pack = require $path;
            $out[basename($path, '.php')] = ['name' => $pack['name'], 'description' => $pack['description'],
                'words' => array_sum(array_column($pack['categories'], 'answers'))];
        }
        uasort($out, fn ($a, $b) => $a['name'] <=> $b['name']);
        return $out;
    }

    // --- Custom packs ---

    public function create(array $user, string $name, string $description, string $text, Dictionary $everyday): array
    {
        if (!Competitions::canOrganise($user)) throw new GameError(403, 'Making your own word packs needs the Organiser plan.');
        $id = 'p' . bin2hex(random_bytes(4));
        [$name, $description, $text, $built] = $this->check($name, $description, $text, $everyday);
        $now = (int) floor(microtime(true) * 1000);
        $this->db->prepare('INSERT INTO packs (id, owner_user_id, name, description, source, format, words, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 2, ?, ?, ?)')
            ->execute([$id, $user['id'], $name, $description, $text, array_sum(array_column($built['categories'], 'answers')), $now, $now]);
        return $this->summary($id, $built);
    }

    public function update(array $user, string $id, string $name, string $description, string $text, Dictionary $everyday): array
    {
        $this->owned($user, $id);
        [$name, $description, $text, $built] = $this->check($name, $description, $text, $everyday);
        $this->db->prepare('UPDATE packs SET name = ?, description = ?, source = ?, format = 2, words = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $description, $text, array_sum(array_column($built['categories'], 'answers')), (int) floor(microtime(true) * 1000), $id]);
        unset($this->built[$id]);
        return $this->summary($id, $built);
    }

    public function delete(array $user, string $id): void
    {
        $this->owned($user, $id);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM worlds WHERE pack = ? AND ended_at IS NULL');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn()) throw new GameError(409, 'A room that is still running uses this pack. End it first.');
        $this->db->prepare('DELETE FROM packs WHERE id = ?')->execute([$id]);
    }

    /**
     * The admin can take down any custom pack. Worlds playing it fall back to everyday words; each
     * one's current prompt is ended first (it may be one of the pack's lists, which would accept
     * nothing), using a Game made by $gameWith from the pack's dictionary.
     *
     * @param Closure(Dictionary): Game $gameWith
     * @return int how many unfinished worlds were playing it
     */
    public function remove(string $id, Dictionary $everyday, Closure $gameWith): int
    {
        $stmt = $this->db->prepare('SELECT id, pack_mode FROM worlds WHERE pack = ? AND ended_at IS NULL');
        $stmt->execute([$id]);
        $worlds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($worlds as $w) $gameWith($this->dictionary($id, $w['pack_mode'] ?? 'only', $everyday))->endPrompt($w['id']);
        $this->db->prepare('DELETE FROM packs WHERE id = ?')->execute([$id]);
        unset($this->built[$id]);
        return count($worlds);
    }

    private function check(string $name, string $description, string $text, Dictionary $everyday): array
    {
        $name = mb_substr(trim($name), 0, 40);
        if ($name === '') throw new GameError(400, 'Give the pack a name');
        $description = mb_substr(trim($description), 0, 200);
        $text = mb_substr(str_replace("\r\n", "\n", $text), 0, 100_000);
        $groups = self::parseText($text);
        if (!$groups) throw new GameError(400, 'Add at least one list: a label line, then one word per line');
        $built = self::build($groups, $everyday, 'x');
        if ($built['problems']) throw new GameError(400, 'Please fix: ' . implode('; ', array_slice($built['problems'], 0, 8)) . (count($built['problems']) > 8 ? '…' : ''));
        $answers = array_sum(array_column($built['categories'], 'answers'));
        if ($answers < self::MIN_WORDS) throw new GameError(400, 'A pack needs at least ' . self::MIN_WORDS . ' words');
        if ($answers > self::MAX_WORDS) throw new GameError(400, 'A pack can have up to ' . self::MAX_WORDS . ' words');
        return [$name, $description, $text, $built];
    }

    private function owned(array $user, string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM packs WHERE id = ?');
        $stmt->execute([$id]);
        $pack = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pack || (int) $pack['owner_user_id'] !== (int) $user['id']) throw new GameError(404, 'No such pack of yours');
        return $pack;
    }

    private function summary(string $id, array $built): array
    {
        return [
            'id' => $id,
            'words' => array_sum(array_column($built['categories'], 'answers')),
            'lists' => array_map(fn ($c) => ['label' => $c['label'], 'words' => $c['answers']], array_values($built['categories'])),
            // Not an error: a gentle "check the spelling?" when saving.
            'unknown' => array_slice($built['unknown'] ?? [], 0, 30),
        ];
    }

    /** The account's own custom packs, with their text, for the pack editor. */
    public function mine(array $user): array
    {
        $stmt = $this->db->prepare('SELECT id, name, description, source, format, words FROM packs WHERE owner_user_id = ? ORDER BY name');
        $stmt->execute([$user['id']]);
        $reported = $this->db->prepare("
            SELECT word, COUNT(DISTINCT player_id) AS players FROM reports
            WHERE category LIKE CONCAT(?, '.%') AND reviewed_at IS NULL GROUP BY word ORDER BY players DESC, word");
        return array_map(function ($p) use ($reported) {
            $reported->execute([$p['id']]);
            return [
                'id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'text' => (int) $p['format'] === 1 ? self::convertOldText($p['source']) : $p['source'],
                'words' => (int) $p['words'],
                // Words players in its worlds said should count: the organiser's to add, or not.
                'reported' => array_map(fn ($r) => ['word' => $r['word'], 'players' => (int) $r['players']], $reported->fetchAll(PDO::FETCH_ASSOC)),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Marks the players' suggestions for one of the account's packs as seen. */
    public function markReportsSeen(array $user, string $id): void
    {
        $this->owned($user, $id);
        $this->db->prepare("UPDATE reports SET reviewed_at = ? WHERE category LIKE CONCAT(?, '.%') AND reviewed_at IS NULL")
            ->execute([(int) floor(microtime(true) * 1000), $id]);
    }

    /** Every custom pack, for the admin page. */
    public function all(): array
    {
        return $this->db->query('
            SELECT p.id, p.name, p.words, p.updated_at, u.name AS owner FROM packs p LEFT JOIN users u ON u.id = p.owner_user_id
            ORDER BY p.updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A custom pack as its owner wrote it, for the admin pages: its name, description, owner and
     * lists (label, then answers, each with its forms), or null if there's no such pack.
     */
    public function details(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT p.*, u.name AS owner, u.email AS owner_email FROM packs p LEFT JOIN users u ON u.id = p.owner_user_id WHERE p.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // The rooms and competitions playing it, which would switch to everyday words without it.
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM worlds WHERE pack = ? AND ended_at IS NULL');
        $stmt->execute([$id]);
        return [
            'id' => $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'owner' => $row['owner'], 'ownerEmail' => $row['owner_email'],
            'words' => (int) $row['words'], 'updatedAt' => (int) $row['updated_at'], 'playing' => (int) $stmt->fetchColumn(),
            'lists' => array_values(self::parseText($row['source'], (int) ($row['format'] ?? 1) === 1)),
        ];
    }

    /** The packs this account may use when creating a world: curated for Host and Organiser, its own custom packs for Organiser. */
    public function available(?array $user): array
    {
        $plan = $user['plan'] ?? 'free';
        $curated = in_array($plan, ['host', 'organiser'], true) ? self::curated() : [];
        $custom = $plan === 'organiser' ? $this->mine($user) : [];
        return [
            'curated' => array_map(fn ($id, $p) => ['id' => $id] + $p, array_keys($curated), $curated),
            'custom' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'description' => $p['description'], 'words' => $p['words']], $custom),
        ];
    }

    /** Checks an account may use a pack for a new world, and returns its name. */
    public function checkUsable(?array $user, string $id): string
    {
        $plan = $user['plan'] ?? 'free';
        $curated = self::curated();
        if (isset($curated[$id])) {
            if (!in_array($plan, ['host', 'organiser'], true)) throw new GameError(403, 'Word packs come with the Host and Organiser plans.');
            return $curated[$id]['name'];
        }
        if ($plan !== 'organiser') throw new GameError(403, 'Your own word packs come with the Organiser plan.');
        return $this->owned($user, $id)['name'];
    }

    // --- Using a pack ---

    /** A built pack (curated or custom), or null if it no longer exists. */
    public function load(string $id, Dictionary $everyday): ?array
    {
        if (isset($this->built[$id])) return $this->built[$id];
        $curatedPath = dirname(__DIR__) . "/data/packs/$id.php";
        if (preg_match('/^[a-z0-9]+$/', $id) && is_file($curatedPath)) return $this->built[$id] = require $curatedPath;

        $stmt = $this->db->prepare('SELECT * FROM packs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        // Built once per change and kept in the cache folder, as building takes a moment. Change
        // the version in the name (-v2) when the built format changes, so old copies aren't read.
        $file = $this->cacheDir ? "{$this->cacheDir}/pack-$id-{$row['updated_at']}-v2.json" : null;
        $cached = $file ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($cached)) {
            $cached = ['name' => $row['name'], 'description' => $row['description']]
                + self::build(self::parseText($row['source'], (int) ($row['format'] ?? 1) === 1), $everyday, $id);
            if ($file) @file_put_contents($file, json_encode($cached));
        }
        return $this->built[$id] = $cached;
    }

    /** The dictionary a world with this pack plays with. Falls back to everyday words if the pack is gone. */
    public function dictionary(string $id, string $mode, Dictionary $everyday): Dictionary
    {
        $pack = $this->load($id, $everyday);
        if (!$pack) return $everyday;
        return $mode === 'only' ? $everyday->onlyPack($pack) : $everyday->withPack($pack);
    }

    public function name(string $id, Dictionary $everyday): ?string
    {
        return $this->load($id, $everyday)['name'] ?? null;
    }

    /** How the pack's phrases are written (joined word => written form), for showing its words. */
    public function displayMap(string $id, Dictionary $everyday): array
    {
        return $this->load($id, $everyday)['display'] ?? [];
    }
}
