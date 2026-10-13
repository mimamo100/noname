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

test('the daily email goes in hourly batches from the morning, once a day, to those who asked and haven\'t played', function () {
    $clock = ms('2026-10-12 05:00 UTC'); // 6am in the UK: too early.
    $db = testDb();
    $streaks = new Streaks($db, 'Europe/London', function () use (&$clock) { return $clock; });
    $reminders = new Reminders($db, $streaks, function () use (&$clock) { return $clock; });
    $log = tempnam(sys_get_temp_dir(), 'mail');
    $mailer = new Mailer(['transport' => 'log', 'log_file' => $log, 'from' => 'noreply@example.com']);
    $site = 'https://unsaid.example/';
    [$ann, $ben, $cy, $dee] = [makeUser($db, 'Ann'), makeUser($db, 'Ben'), makeUser($db, 'Cy'), makeUser($db, 'Dee')];
    foreach ([$ann, $ben, $cy] as $u) $reminders->set($u['id'], true);
    $clock -= 86_400_000;
    $streaks->played($ann['id']); // Yesterday.
    $clock += 86_400_000;
    $streaks->played($ben['id']); // Already played today.

    same(0, $reminders->send($mailer, $site, perRun: 1, fromHour: 7), 'Not before 7am');
    same(null, Reminders::lastRun($db));
    $clock += 2 * 3_600_000; // 8am.
    same(1, $reminders->send($mailer, $site, perRun: 1, fromHour: 7), 'One batch of one: Ann');
    $last = Reminders::lastRun($db);
    same([1, 1, 1, false], [$last['sent'], $last['sentToday'], $last['due'], $last['refused']], 'Cy is still due');
    $clock += 3_600_000; // 9am: the next batch.
    same(1, $reminders->send($mailer, $site, perRun: 1, fromHour: 7), 'Cy');
    same([2, 0], [Reminders::lastRun($db)['sentToday'], Reminders::lastRun($db)['due']]);
    same(0, $reminders->send($mailer, $site, perRun: 1, fromHour: 7), 'Once a day');
    $stats = (new Admin($db, testDictionary()))->emailStats();
    same(3, $stats['remindersOn']);

    $mail = file_get_contents($log);
    check(str_contains($mail, 'ann@example.com') && str_contains($mail, 'cy@example.com') && !str_contains($mail, 'ben@example.com'));
    check(str_contains($mail, 'Play: https://unsaid.example/'));
    preg_match('/To: ann@example.com.*?stop-emails=([0-9a-f]{32})/s', $mail, $m);
    check($reminders->stop($m[1]));
    check(!$reminders->stop('0123456789abcdef0123456789abcdef'));
    $reminders->set($cy['id'], false);

    $clock += 86_400_000; // Tomorrow at 9am: Ben, not Ann, who stopped them.
    file_put_contents($log, '');
    same(1, $reminders->send($mailer, $site, perRun: 10, fromHour: 7));
    check(str_contains(file_get_contents($log), 'ben@example.com') && !str_contains(file_get_contents($log), 'ann@example.com'));
    same(1, Reminders::lastRun($db)['sentToday'], 'A new day starts the count again');
    unlink($log);
});
