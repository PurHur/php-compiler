<?php
declare(strict_types=1);

$d = new DateTime('2020-06-01 12:00:00', new DateTimeZone('America/New_York'));
$expect = [
    'T' => 'EDT',
    'e' => 'America/New_York',
    'P' => '-04:00',
    'O' => '-0400',
    'Z' => '-14400',
    'r' => 'Mon, 01 Jun 2020 12:00:00 -0400',
];
$ok = true;
foreach ($expect as $token => $want) {
    $got = $d->format($token);
    if ($got !== $want) {
        echo "fail: token=$token got=$got want=$want\n";
        $ok = false;
    }
}
$d2 = new DateTime('2020-06-01 12:00:00', new DateTimeZone('+04:00'));
if ('GMT+0400' !== $d2->format('T')) {
    echo 'fail: offset T=', $d2->format('T'), " want=GMT+0400\n";
    $ok = false;
}
echo $ok ? "ok\n" : "fail\n";
exit($ok ? 0 : 1);
