<?php
declare(strict_types=1);

// Runs the real API through PHP's built-in web server against the test database.
test('HTTP API: sign in, create, play, look up', function () {
    testDb(); // Start from empty tables.
    $mailLog = tempnam(sys_get_temp_dir(), 'unsaidmail');
    $config = tempnam(sys_get_temp_dir(), 'unsaidconfig');
    $cacheDir = sys_get_temp_dir() . '/unsaid-api-cache-' . bin2hex(random_bytes(4));
    file_put_contents($config, '<?php return ' . var_export([
        'db_host' => getenv('TEST_DB_HOST') ?: 'localhost',
        'db_name' => getenv('TEST_DB_NAME'),
        'db_user' => getenv('TEST_DB_USER') ?: 'root',
        'db_pass' => getenv('TEST_DB_PASS') ?: '',
        'mail' => ['transport' => 'log', 'log_file' => $mailLog, 'from' => 'noreply@example.com'],
        'cache_dir' => $cacheDir,
    ], true) . ';');
    $port = random_int(20000, 40000);
    $public = dirname(__DIR__) . '/public';
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $public, "$public/router.php"],
        [1 => ['file', $devNull = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', $devNull, 'w']],
        $pipes,
        null,
        ['UNSAID_CONFIG' => $config] + getenv(),
    );
    try {
        $base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100_000);

        $call = function (string $method, string $path, ?array $body = null, ?string $session = null) use ($base): array {
            $headers = ['Content-Type: application/json'];
            if ($session) $headers[] = "X-Session-Token: $session";
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body === null ? '' : json_encode($body),
                'ignore_errors' => true,
            ]]);
            $response = file_get_contents($base . $path, false, $context);
            preg_match('{HTTP/\S+ (\d+)}', $http_response_header[0], $m);
            return ['status' => (int) $m[1], 'body' => json_decode($response, true), 'raw' => $response];
        };
        $lastCode = function () use ($mailLog): string {
            preg_match_all('/code: (\d{6})/', file_get_contents($mailLog), $m);
            return end($m[1]);
        };

        // Without an account: watching works, playing and creating don't.
        same(200, $call('GET', '/api/worlds/big')['status']);
        same(null, $call('GET', '/api/worlds/big')['body']['account']);
        same(401, $call('POST', '/api/worlds', ['name' => 'Nope'])['status']);
        same(401, $call('POST', '/api/worlds/big/words', ['word' => 'cat'])['status']);
        same(null, $call('GET', '/api/auth/me')['body']['user']);

        // Signing up.
        same(200, $call('POST', '/api/auth/start', ['email' => 'kim@example.com'])['status']);
        check(str_contains(file_get_contents($mailLog), 'To: kim@example.com'), 'The code was emailed');
        same(true, $call('POST', '/api/auth/verify', ['email' => 'kim@example.com', 'code' => $lastCode()])['body']['needsName']);
        $signedIn = $call('POST', '/api/auth/verify', ['email' => 'kim@example.com', 'code' => $lastCode(), 'name' => 'Kim']);
        same(200, $signedIn['status']);
        $session = $signedIn['body']['token'];
        same('Kim', $call('GET', '/api/auth/me', null, $session)['body']['user']['name']);
        same(400, $call('POST', '/api/auth/verify', ['email' => 'kim@example.com', 'code' => '000000'])['status']);

        same(403, $call('POST', '/api/worlds', ['name' => 'Free'], $session)['status'], 'Private worlds need the Host plan');
        testDbConnection()->exec("UPDATE users SET plan = 'host' WHERE email = 'kim@example.com'");
        same('host', $call('GET', '/api/auth/me', null, $session)['body']['user']['plan']);
        $brits = $call('POST', '/api/worlds', ['name' => 'Brits', 'spelling' => 'uk', 'promptHours' => 4], $session)['body'];
        same('uk', $call('GET', "/api/worlds/{$brits['id']}", null, $session)['body']['world']['spelling']);
        same(400, $call('POST', '/api/worlds', ['name' => 'Nope', 'spelling' => 'fr'], $session)['status']);
        $created = $call('POST', '/api/worlds', ['name' => 'Friends'], $session);
        same(201, $created['status'], 'Create world.');
        $id = $created['body']['id'];

        // Private: only with the invite link, or as the owner or a player.
        same(403, $call('GET', "/api/worlds/$id")['status']);
        same(200, $call('GET', "/api/worlds/$id?invite={$created['body']['invite']}")['status']);
        same(true, $call('GET', "/api/worlds/$id", null, $session)['body']['isOwner']);
        same(4, $call('GET', "/api/worlds/{$brits['id']}/settings", null, $session)['body']['promptHours']);
        same(401, $call('GET', "/api/worlds/$id/settings")['status']);

        $state = $call('GET', "/api/worlds/$id", null, $session);
        same('Kim', $state['body']['account']['name']);
        $same = $call('GET', "/api/worlds/$id?since={$state['body']['version']}", null, $session)['body'];
        same([true, $state['body']['version']], [$same['unchanged'], $same['version']], 'Nothing new: a short answer');
        check(!isset($same['said']));
        check(!isset($call('GET', "/api/worlds/$id?since={$state['body']['version']}")['body']['unchanged']), 'Different viewer, different answer');
        same(null, $state['body']['me'], 'Not a player until the first word');
        $spec = json_decode(testDbConnection()->query("SELECT spec FROM prompts WHERE world_id = '$id'")->fetchColumn(), true);
        $word = Prompts::fitting($spec, Dictionary::load())[0];

        $played = $call('POST', "/api/worlds/$id/words", ['word' => $word], $session);
        same(true, $played['body']['ok'], 'Play.');
        same('Kim', $call('GET', "/api/worlds/$id", null, $session)['body']['me'], 'Joined on the first word');
        same('Kim', $call('GET', "/api/worlds/$id/words/$word", null, $session)['body']['burned']['by']);
        same(403, $call('GET', "/api/worlds/$id/words/$word")['status'], 'Private: not without the link');
        $again = $call('POST', "/api/worlds/$id/words", ['word' => $word], $session)['body'];
        same(['already-burned', 0, true], [$again['reason'], $again['penalty'], $again['byYou']], 'Repeating your own word is free.');
        same(401, $call('POST', "/api/worlds/$id/reports?invite={$created['body']['invite']}", ['word' => 'sofa'])['status'], 'Reports need an account.');

        $room = $call('POST', '/api/rooms', null, $session)['body'];
        same('Room 1', $room['name']);
        same($room, $call('GET', '/api/worlds/big', null, $session)['body']['myRoom']);
        same(true, $call('POST', "/api/worlds/{$room['id']}/join", null, $session)['status'] === 201);
        same(401, $call('POST', '/api/rooms')['status']);
        same([], $call('GET', '/api/me/history', null, $session)['body']['games']);

        // Your account: your place in each world, and a new name.
        $mine = array_column($call('GET', '/api/me/worlds', null, $session)['body']['worlds'], null, 'id');
        same(1, $mine[$id]['place'], 'First in the world just played');
        same(null, $mine[$room['id']]['place'], 'No place before a word');
        same(401, $call('POST', '/api/me/name', ['name' => 'Kimberley'])['status'], 'Renaming needs an account');
        same('Kimberley', $call('POST', '/api/me/name', ['name' => 'Kimberley'], $session)['body']['user']['name']);
        same('Kimberley', $call('GET', "/api/worlds/$id", null, $session)['body']['me'], 'The new name shows in the world');
        check(str_contains($call('GET', '/me')['raw'], 'Your account'), 'The account page serves index.html');

        same(200, $call('POST', '/api/auth/logout', null, $session)['status']);
        same(401, $call('POST', "/api/worlds/$id/words?invite={$created['body']['invite']}", ['word' => $word], $session)['status'], 'Signed out');

        same(404, $call('GET', '/api/worlds/nope')['status']);
        $big = $call('GET', '/api/worlds/big', null, $session)['body'];
        check(str_starts_with($big['world']['name'], 'The Big World, '), 'The big world');
        same(['public', null, null], [$big['world']['kind'], $big['lastRound'], $big['myRoom']]);
        check(str_contains($call('GET', "/w/$id")['raw'], 'Unsaid'), 'World page should serve index.html');

        // Deleting the account: it needs confirming, then the email can sign up afresh.
        $call('POST', '/api/auth/start', ['email' => 'kim@example.com']);
        $session = $call('POST', '/api/auth/verify', ['email' => 'kim@example.com', 'code' => $lastCode()])['body']['token'];
        same(400, $call('POST', '/api/me/delete', [], $session)['status']);
        same(200, $call('POST', '/api/me/delete', ['confirm' => true], $session)['status']);
        same(null, $call('GET', '/api/auth/me', null, $session)['body']['user']);
        same('Former player', $call('GET', "/api/worlds/$id?invite={$created['body']['invite']}")['body']['leaderboard'][0]['nickname']);
    } finally {
        proc_terminate($server);
        unlink($config);
        @unlink($mailLog);
        array_map('unlink', glob("$cacheDir/*"));
        @rmdir($cacheDir);
    }
});
