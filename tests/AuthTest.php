<?php
declare(strict_types=1);

/** Keeps emails in memory instead of sending them. */
final class TestMailer extends Mailer
{
    public array $sent = [];
    public bool $fail = false;

    public function __construct()
    {
        parent::__construct([]);
    }

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fail) throw new RuntimeException('Mail server down');
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }

    public function lastCode(): string
    {
        preg_match('/\b(\d{6})\b/', end($this->sent)['subject'], $m);
        return $m[1];
    }
}

function makeAuth(PDO $db, TestMailer $mailer, ?callable $clock = null): Auth
{
    return new Auth($db, $mailer, testDictionary(), $clock ?? fn () => 1_000_000);
}

/** Signs a new account in and returns [user, session token]. */
function signUp(Auth $auth, TestMailer $mailer, string $email, string $name, string $ip = '1.2.3.4'): array
{
    $auth->start($email, $ip);
    $result = $auth->verify($email, $mailer->lastCode(), $name);
    return [$result['user'], $result['token']];
}

test('signing up: code by email, then a name, then a session', function () {
    $db = testDb();
    $mailer = new TestMailer();
    $auth = makeAuth($db, $mailer);

    $auth->start('  Ann@Example.com ', '1.2.3.4');
    same('ann@example.com', $mailer->sent[0]['to'], 'Emails are normalised');
    check(str_contains($mailer->sent[0]['body'], $mailer->lastCode()), 'The code is in the email');

    same(['needsName' => true], $auth->verify('ann@example.com', $mailer->lastCode()), 'A new email must choose a name');
    $result = $auth->verify('ANN@example.com', $mailer->lastCode(), 'Ann');
    same('Ann', $result['user']['name']);
    same(64, strlen($result['token']));
    same('Ann', $auth->user($result['token'])['name']);
    same(null, $auth->user('not-a-session'));
    throwsStatus(400, fn () => $auth->verify('ann@example.com', $mailer->lastCode(), 'Ann'), 'Codes work once');

    // Signing in again on another device: no name needed, a second session.
    $auth->start('ann@example.com', '5.6.7.8');
    $again = $auth->verify('ann@example.com', $mailer->lastCode());
    same('Ann', $again['user']['name']);
    check($again['token'] !== $result['token']);

    $auth->logout($result['token']);
    same(null, $auth->user($result['token']), 'Signed out');
    same('Ann', $auth->user($again['token'])['name'], 'Other devices stay signed in');
});

test('wrong and expired codes', function () {
    $clock = 1_000_000;
    $db = testDb();
    $mailer = new TestMailer();
    $auth = makeAuth($db, $mailer, function () use (&$clock) { return $clock; });
    $auth->start('bo@example.com', '1.2.3.4');
    $code = $mailer->lastCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    for ($i = 0; $i < Auth::CODE_ATTEMPTS; $i++) throwsStatus(400, fn () => $auth->verify('bo@example.com', $wrong, 'Bo'));
    throwsStatus(400, fn () => $auth->verify('bo@example.com', $code, 'Bo'), 'Locked after too many tries');

    $auth->start('bo@example.com', '1.2.3.4');
    $clock += (Auth::CODE_MINUTES + 1) * 60_000;
    throwsStatus(400, fn () => $auth->verify('bo@example.com', $mailer->lastCode(), 'Bo'), 'Expired');
});

test('sign-in codes are rate limited per email and per address', function () {
    $clock = 1_000_000;
    $db = testDb();
    $mailer = new TestMailer();
    $auth = makeAuth($db, $mailer, function () use (&$clock) { return $clock; });
    for ($i = 0; $i < Auth::CODES_PER_EMAIL_PER_HOUR; $i++) $auth->start('cy@example.com', "10.0.0.$i");
    throwsStatus(429, fn () => $auth->start('cy@example.com', '10.0.0.99'));
    for ($i = 0; $i < Auth::CODES_PER_IP_PER_HOUR; $i++) $auth->start("p$i@example.com", '9.9.9.9');
    throwsStatus(429, fn () => $auth->start('new@example.com', '9.9.9.9'));
    $clock += 3_600_001;
    $auth->start('cy@example.com', '9.9.9.9');
    same(Auth::CODES_PER_EMAIL_PER_HOUR + Auth::CODES_PER_IP_PER_HOUR + 1, count($mailer->sent), 'Allowed again an hour later');

    // A daily cap per email, so nobody can keep guessing one person's codes: one code an
    // hour (well under the hourly limit) still stops at the daily limit.
    for ($i = 0; $i < Auth::CODES_PER_EMAIL_PER_DAY; $i++) {
        $auth->start('dee@example.com', "8.8.8.$i");
        $clock += 3_600_001;
    }
    throwsStatus(429, fn () => $auth->start('dee@example.com', '6.6.6.6'), 'Daily cap reached');
});

test('bad emails, taken and offensive names, and mail failures', function () {
    $db = testDb();
    $mailer = new TestMailer();
    $auth = makeAuth($db, $mailer);
    throwsStatus(400, fn () => $auth->start('not an email', '1.1.1.1'));
    signUp($auth, $mailer, 'di@example.com', 'Di');
    $auth->start('other@example.com', '1.1.1.1');
    throwsStatus(409, fn () => $auth->verify('other@example.com', $mailer->lastCode(), 'di'), 'Names are unique, ignoring case');
    throwsStatus(400, fn () => $auth->verify('other@example.com', $mailer->lastCode(), 'Gross'), 'Offensive names are refused');
    throwsStatus(400, fn () => $auth->verify('other@example.com', $mailer->lastCode(), '<b>'), 'Names are checked');

    $mailer->fail = true;
    throwsStatus(503, fn () => $auth->start('ed@example.com', '1.1.1.1'));
    same(0, (int) $db->query("SELECT COUNT(*) FROM login_codes WHERE email = 'ed@example.com'")->fetchColumn(), 'Failed codes are removed');
});

test('accounts play in worlds, and claim players from before accounts', function () {
    $db = testDb();
    $mailer = new TestMailer();
    $auth = makeAuth($db, $mailer);
    $game = new Game($db, testDictionary(), fn () => 1_000_000, promptMin: 1, promptMax: 1000);
    $world = $game->createWorld('Old friends');
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));

    // A player from before accounts, and someone else already called "Fay" there.
    $old = $game->join($world['id'], 'Old Me');
    check($game->play($world['id'], $old['token'], 'moon')['ok']);
    $game->join($world['id'], 'Fay');

    $auth->start('fay@example.com', '1.1.1.1');
    $signedIn = $auth->verify('fay@example.com', $mailer->lastCode(), 'Fay', [$old['token'], 'bogus']);
    $fay = $signedIn['user'];
    same('Old Me', $game->playerForUser($world['id'], $fay['id'])['nickname'], 'The old player is claimed, scores and all');

    // In a new world the account joins with its name, adding a number if the name is taken.
    $other = $game->createWorld('Second', null, Game::DEFAULT_PROMPT_DURATION_MS, 'both', $fay['id']);
    same($fay['id'], (int) $game->world($other['id'])['owner_user_id'], 'The creator is recorded as owner');
    $game->join($other['id'], 'Fay');
    same('Fay 2', $game->joinAsUser($other['id'], $fay)['nickname']);
    same('Fay 2', $game->joinAsUser($other['id'], $fay)['nickname'], 'Joining twice is the same player');
});
