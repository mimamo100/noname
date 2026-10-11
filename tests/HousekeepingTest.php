<?php
declare(strict_types=1);

test('housekeeping clears old codes, sessions, free misses and finished worlds\' detail', function () {
    $clock = 100 * DAY_MS;
    $game = new Game(testDb(), testDictionary(), function () use (&$clock) { return $clock; }, promptMin: 1, promptMax: 1000);
    $db = gameDb($game);
    $count = fn (string $sql) => (int) $db->query($sql)->fetchColumn();

    // Sign-in codes and sessions, old and new.
    $user = makeUser($db, 'Ann');
    foreach ([97, 99] as $day) {
        $db->prepare("INSERT INTO login_codes (email, code_hash, ip, created_at, expires_at) VALUES ('a@example.com', 'x', '1.1.1.1', ?, ?)")
            ->execute([$day * DAY_MS, $day * DAY_MS]);
    }
    $db->prepare('INSERT INTO sessions (token_hash, user_id, created_at, expires_at) VALUES (?, ?, 0, ?), (?, ?, 0, ?)')
        ->execute(['old', $user['id'], 99 * DAY_MS, 'new', $user['id'], 200 * DAY_MS]);

    // A world that finished long ago, and one still going, each with an old free miss and penalty.
    $clock = 10 * DAY_MS;
    $done = $game->createWorld('Done');
    $live = $game->createWorld('Live');
    foreach ([$done, $live] as $world) {
        setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
        $p = $game->join($world['id'], 'Ann');
        $game->play($world['id'], $p['token'], 'moon');
        $game->play($world['id'], $p['token'], 'xyzzy'); // A typo: free.
        $game->play($world['id'], $p['token'], 'cat'); // Doesn't fit: a penalty.
    }
    $score = (int) $db->query("SELECT score FROM players WHERE world_id = '{$done['id']}'")->fetchColumn();
    $game->finishWorld($done['id']);
    $clock = 100 * DAY_MS;
    $game->play($live['id'], $p['token'], 'zzzz'); // A recent free miss.

    same(null, Housekeeping::lastRun($db));
    $result = (new Housekeeping($db, 30, null, fn () => $clock))->run();
    // Both worlds' old typos go as free misses; the finished world's penalty goes with its detail.
    same([1, 1, 2, 1, 2, 1], [$result['signInCodes'], $result['sessions'], $result['freeMisses'], $result['finishedWorlds'], $result['words'], $result['misses']]);
    same(1, $count('SELECT COUNT(*) FROM login_codes'));
    same('new', $db->query('SELECT token_hash FROM sessions')->fetchColumn());
    same(0, $count("SELECT COUNT(*) FROM burns WHERE world_id = '{$done['id']}'"));
    same(1, $count("SELECT COUNT(*) FROM results WHERE world_id = '{$done['id']}'"), 'Final standings stay');
    same(['moons', 'moon'], $db->query("SELECT word FROM burns WHERE world_id = '{$live['id']}' ORDER BY word DESC")->fetchAll(PDO::FETCH_COLUMN));
    same(['cat', 'zzzz'], $db->query("SELECT word FROM misses WHERE world_id = '{$live['id']}' ORDER BY word")->fetchAll(PDO::FETCH_COLUMN), 'Penalties and recent misses stay');

    Game::recountScores($db);
    same($score, (int) $db->query("SELECT score FROM players WHERE world_id = '{$done['id']}'")->fetchColumn(), 'Finished scores stand');
    same($clock, Housekeeping::lastRun($db)['at']);
    same(0, (new Housekeeping($db, 30, null, fn () => $clock))->run()['words'], 'Nothing left to do');
});

test('housekeeping removes cache files nobody has used for a day', function () {
    $dir = sys_get_temp_dir() . '/unsaid-hk-' . bin2hex(random_bytes(4));
    mkdir($dir);
    touch("$dir/old.json", time() - 2 * 86_400);
    touch("$dir/new.json");
    same(1, (new Housekeeping(testDb(), 30, $dir))->run()['cacheFiles']);
    same(['new.json'], array_map('basename', glob("$dir/*")));
    unlink("$dir/new.json");
    rmdir($dir);
});
