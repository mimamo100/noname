<?php
declare(strict_types=1);

// Runs the real API through PHP's built-in web server against the test database.
test('HTTP API: create, join, play, look up', function () {
    testDb(); // Start from empty tables.
    $config = tempnam(sys_get_temp_dir(), 'bwconfig');
    file_put_contents($config, '<?php return ' . var_export([
        'db_host' => getenv('TEST_DB_HOST') ?: 'localhost',
        'db_name' => getenv('TEST_DB_NAME'),
        'db_user' => getenv('TEST_DB_USER') ?: 'root',
        'db_pass' => getenv('TEST_DB_PASS') ?: '',
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

        $call = function (string $method, string $path, ?array $body = null, ?string $token = null) use ($base): array {
            $headers = ['Content-Type: application/json'];
            if ($token) $headers[] = "X-Player-Token: $token";
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

        same('uk', $call('POST', '/api/worlds', ['name' => 'Brits', 'spelling' => 'uk'])['body']['spelling']);
        same(400, $call('POST', '/api/worlds', ['name' => 'Nope', 'spelling' => 'fr'])['status']);
        $created = $call('POST', '/api/worlds', ['name' => 'Friends']);
        same(201, $created['status'], 'Create world.');
        $id = $created['body']['id'];

        $joined = $call('POST', "/api/worlds/$id/join", ['nickname' => 'Kim']);
        same(201, $joined['status'], 'Join.');
        $token = $joined['body']['token'];

        $state = $call('GET', "/api/worlds/$id", null, $token);
        same('Kim', $state['body']['me']);
        $spec = json_decode(testDbConnection()->query("SELECT spec FROM prompts WHERE world_id = '$id'")->fetchColumn(), true);
        $word = Prompts::fitting($spec, Dictionary::load())[0];

        $played = $call('POST', "/api/worlds/$id/words", ['word' => $word], $token);
        same(true, $played['body']['ok'], 'Play.');
        same('Kim', $call('GET', "/api/worlds/$id/words/$word")['body']['burned']['by']);

        same(401, $call('POST', "/api/worlds/$id/words", ['word' => $word])['status']);
        same(Game::PENALTY, $call('POST', "/api/worlds/$id/words", ['word' => $word], $token)['body']['penalty'], 'Already said.');
        same(400, $call('POST', "/api/worlds/$id/join", ['nickname' => 'fuck'])['status'], 'Offensive nickname.');
        same(401, $call('POST', "/api/worlds/$id/reports", ['word' => 'sofa'])['status'], 'Reports need a player.');
        same(404, $call('GET', '/api/worlds/nope')['status']);
        same(400, $call('POST', "/api/worlds/$id/join", null)['status']);
        same('The Public World', $call('GET', '/api/worlds/public')['body']['world']['name']);
        check(str_contains($call('GET', "/w/$id")['raw'], 'Unsaid'), 'World page should serve index.html');
    } finally {
        proc_terminate($server);
        unlink($config);
    }
});
