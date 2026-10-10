<?php
// Test runner with no dependencies. Needs an empty MySQL/MariaDB database it can wipe:
//
//   TEST_DB_NAME=unsaid_test TEST_DB_USER=bw TEST_DB_PASS=secret php tests/run.php
//
// Optional: TEST_DB_HOST (default localhost).

declare(strict_types=1);

$root = dirname(__DIR__);
require "$root/src/WordFamilies.php";
require "$root/src/Dictionary.php";
require "$root/src/Prompts.php";
require "$root/src/Scoring.php";
require "$root/src/Game.php";

$tests = [];
function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) throw new RuntimeException($message);
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(trim("$message Expected " . json_encode($expected) . ', got ' . json_encode($actual)));
    }
}

function throwsStatus(int $status, callable $fn): void
{
    try {
        $fn();
    } catch (GameError $e) {
        same($status, $e->status, 'Wrong status.');
        return;
    }
    throw new RuntimeException("Expected GameError $status");
}

function testDbConnection(): PDO
{
    $name = getenv('TEST_DB_NAME') ?: throw new RuntimeException('Set TEST_DB_NAME (and TEST_DB_USER, TEST_DB_PASS) to an empty test database');
    return new PDO(
        'mysql:host=' . (getenv('TEST_DB_HOST') ?: 'localhost') . ";dbname=$name;charset=utf8mb4",
        getenv('TEST_DB_USER') ?: 'root',
        getenv('TEST_DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** A connection to the test database with freshly created, empty tables. */
function testDb(): PDO
{
    $db = testDbConnection();
    $db->exec('DROP TABLE IF EXISTS reports, misses, burns, prompts, players, worlds');
    Game::installSchema($db);
    return $db;
}

require __DIR__ . '/GameTest.php';
require __DIR__ . '/ApiTest.php';

$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name\n     {$e->getMessage()}\n     at {$e->getFile()}:{$e->getLine()}\n";
    }
}
echo "\n" . (count($tests) - $failed) . ' passed, ' . $failed . " failed\n";
exit($failed ? 1 : 0);
