<?php
declare(strict_types=1);
// Repro #24395 — back|front of YYYY-MM-DD (timelib hour24 + date remainder)
$base = new DateTimeImmutable('2024-01-31 10:00:00');
$cases = [
    'back of 2024-01-15' => '2024-01-15 20:15:00',
    'front of 2024-01-15' => '2024-01-15 19:45:00',
    'back of 1999-01-15' => '1999-01-15 19:15:00',
    'front of 1999-01-15' => '1999-01-15 18:45:00',
    'back of 9 2024-01-15' => '2024-01-15 09:15:00',
    'back of 9am' => '2024-01-31 09:15:00',
    'front of 5pm' => '2024-01-31 16:45:00',
    'back of 12' => '2024-01-31 12:15:00',
];
$failed = 0;
foreach ($cases as $phrase => $expect) {
    $m = @$base->modify($phrase);
    $got = false === $m ? 'false' : $m->format('Y-m-d H:i:s');
    $ok = $got === $expect;
    if (!$ok) {
        ++$failed;
    }
    echo 'modify ', $phrase, ' => ', $got, ($ok ? ' OK' : ' FAIL want '.$expect), "\n";
}
foreach (['back of 2024-01-15' => '2024-01-15 20:15:00', 'front of 2024-01-15' => '2024-01-15 19:45:00'] as $phrase => $expect) {
    $t = @strtotime($phrase, $base->getTimestamp());
    $got = false === $t ? 'false' : date('Y-m-d H:i:s', $t);
    $ok = $got === $expect;
    if (!$ok) {
        ++$failed;
    }
    echo 'strtotime ', $phrase, ' => ', $got, ($ok ? ' OK' : ' FAIL want '.$expect), "\n";
}
exit($failed === 0 ? 0 : 1);
