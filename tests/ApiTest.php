<?php
declare(strict_types=1);

// Runs the real API through PHP's built-in web server against the test database.
test('HTTP API: sign in, create, play, look up', function () {
    testDb(); // Start from empty tables.
    $mailLog = tempnam(sys_get_temp_dir(), 'unsaidmail');
    $config = tempnam(sys_get_temp_dir(), 'unsaidconfig');
    file_put_contents($config, '<?php return ' . var_export([
        'db_host' => getenv('TEST_DB_HOST') ?: 'localhost',
        'db_name' => getenv('TEST_DB_NAME'),
        'db_user' => getenv('TEST_DB_USER') ?: 'root',
        'db_pass' => getenv('TEST_DB_PASS') ?: '',
        'mail' => ['transport' => 'log', 'log_file' => $mailLog, 'from' => 'noreply@example.com'],
    ], true) . ';');
    $port = random_int(20000, 40000);
    $public = dirname(__DIR__) . '/public';
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $public, "$public/router.php"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
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
        same(200, $call('GET', '/api/worlds/public')['status']);
        same(null, $call('GET', '/api/worlds/public')['body']['account']);
        same(401, $call('POST', '/api/worlds', ['name' => 'Nope'])['status']);
        same(401, $call('POST', '/api/worlds/public/words', ['word' => 'cat'])['status']);
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

        same('uk', $call('POST', '/api/worlds', ['name' => 'Brits', 'spelling' => 'uk'], $session)['body']['spelling']);
        same(400, $call('POST', '/api/worlds', ['name' => 'Nope', 'spelling' => 'fr'], $session)['status']);
        $created = $call('POST', '/api/worlds', ['name' => 'Friends'], $session);
        same(201, $created['status'], 'Create world.');
        $id = $created['body']['id'];

        $state = $call('GET', "/api/worlds/$id", null, $session);
        same('Kim', $state['body']['account']['name']);
        same(null, $state['body']['me'], 'Not a player until the first word');
        $spec = json_decode(testDbConnection()->query("SELECT spec FROM prompts WHERE world_id = '$id'")->fetchColumn(), true);
        $word = Prompts::fitting($spec, Dictionary::load())[0];

        $played = $call('POST', "/api/worlds/$id/words", ['word' => $word], $session);
        same(true, $played['body']['ok'], 'Play.');
        same('Kim', $call('GET', "/api/worlds/$id", null, $session)['body']['me'], 'Joined on the first word');
        same('Kim', $call('GET', "/api/worlds/$id/words/$word")['body']['burned']['by']);
        same(Game::PENALTY, $call('POST', "/api/worlds/$id/words", ['word' => $word], $session)['body']['penalty'], 'Already said.');
        same(401, $call('POST', "/api/worlds/$id/reports", ['word' => 'sofa'])['status'], 'Reports need an account.');

        same(200, $call('POST', '/api/auth/logout', null, $session)['status']);
        same(401, $call('POST', "/api/worlds/$id/words", ['word' => $word], $session)['status'], 'Signed out');

        same(404, $call('GET', '/api/worlds/nope')['status']);
        same('The Public World', $call('GET', '/api/worlds/public')['body']['world']['name']);
        check(str_contains($call('GET', "/w/$id")['raw'], 'Unsaid'), 'World page should serve index.html');
    } finally {
        proc_terminate($server);
        unlink($config);
        @unlink($mailLog);
    }
});
