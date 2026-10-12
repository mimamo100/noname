<?php
declare(strict_types=1);

test('streaks count days in a row with a word said, in UK days', function () {
    $clock = ms('2026-10-12 10:00 UTC');
    $db = testDb();
    $streaks = new Streaks($db, 'Europe/London', function () use (&$clock) { return $clock; });
    $ann = makeUser($db, 'Ann');
    same(['streak' => 0, 'bestStreak' => 0, 'playedToday' => false], $streaks->of($ann['id']));
    same(1, $streaks->played($ann['id']), 'First word: day 1');
    same(null, $streaks->played($ann['id']), 'Same day: nothing new');
    $clock = ms('2026-10-12 23:30 UTC'); // 00:30 on the 13th in the UK: the next day.
    same(2, $streaks->played($ann['id']));
    $clock = ms('2026-10-14 12:00 UTC');
    same(2, $streaks->of($ann['id'])['streak'], 'Still alive until today ends');
    same(3, $streaks->played($ann['id']));
    $clock = ms('2026-10-16 12:00 UTC'); // Missed the 15th.
    same(0, $streaks->of($ann['id'])['streak']);
    same(1, $streaks->played($ann['id']), 'Starts again');
    same(3, $streaks->of($ann['id'])['bestStreak'], 'The best run is kept');
});

test('a day\'s first word reports the streak', function () {
    $clock = ms('2026-10-12 10:00 UTC');
    $db = testDb();
    $streaks = new Streaks($db, 'Europe/London', function () use (&$clock) { return $clock; });
    $game = new Game($db, testDictionary(), function () use (&$clock) { return $clock; }, promptMin: 1, promptMax: 1000, streaks: $streaks);
    $world = $game->createWorld('W', null, 100 * 86_400_000);
    setPrompt($game, $world['id'], spec(null, ['contains', 'oo']));
    $ann = $game->joinAsUser($world['id'], makeUser($db, 'Ann'));
    same(1, $game->play($world['id'], $ann['token'], 'moon')['streak']);
    check(!isset($game->play($world['id'], $ann['token'], 'book')['streak']));
    $clock += 86_400_000;
    same(2, $game->play($world['id'], $ann['token'], 'cool')['streak']);
});

test('the daily email goes once a day to those who asked and haven\'t played, and its link stops it', function () {
    $clock = ms('2026-10-12 03:00 UTC');
    $db = testDb();
    $streaks = new Streaks($db, 'Europe/London', function () use (&$clock) { return $clock; });
    $reminders = new Reminders($db, $streaks);
    $log = tempnam(sys_get_temp_dir(), 'mail');
    $mailer = new Mailer(['transport' => 'log', 'log_file' => $log, 'from' => 'noreply@example.com']);
    [$ann, $ben, $cy] = [makeUser($db, 'Ann'), makeUser($db, 'Ben'), makeUser($db, 'Cy')];
    $reminders->set($ann['id'], true);
    $reminders->set($ben['id'], true);
    $clock -= 86_400_000;
    $streaks->played($ann['id']); // Yesterday.
    $clock += 86_400_000;
    $streaks->played($ben['id']); // Already played today.

    same(1, $reminders->send($mailer, 'https://unsaid.example/'));
    $mail = file_get_contents($log);
    check(str_contains($mail, 'ann@example.com') && !str_contains($mail, 'ben@example.com') && !str_contains($mail, 'cy@example.com'));
    check(str_contains($mail, 'Play: https://unsaid.example/'));
    same(0, $reminders->send($mailer, 'https://unsaid.example/'), 'Once a day');

    preg_match('/stop-emails=([0-9a-f]{32})/', $mail, $m);
    check($reminders->stop($m[1]));
    check(!$reminders->stop('0123456789abcdef0123456789abcdef'));
    $clock += 86_400_000;
    file_put_contents($log, '');
    same(1, $reminders->send($mailer, 'https://unsaid.example/'), 'Tomorrow: Ben, not Ann, who stopped them');
    check(str_contains(file_get_contents($log), 'ben@example.com') && !str_contains(file_get_contents($log), 'ann@example.com'));
    unlink($log);
});
