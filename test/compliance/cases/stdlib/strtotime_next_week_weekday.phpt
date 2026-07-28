--TEST--
stdlib strtotime()/modify — next week Monday (week token first) (#24018, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2024-01-31 12:00:00');
$cases = [
    'next week Monday' => '2024-02-05 00:00:00',
    'Monday next week' => '2024-02-05 00:00:00',
    'last week Friday' => '2024-01-26 00:00:00',
    'this week Wednesday' => '2024-01-31 00:00:00',
    '2020-01-15 next week Monday' => '2020-01-20 00:00:00',
    '2020-01-15 Monday next week' => '2020-01-20 00:00:00',
];
foreach ($cases as $phrase => $expect) {
    $got = strtotime($phrase, $base);
    $fmt = false === $got ? 'false' : date('Y-m-d H:i:s', $got);
    if ($fmt !== $expect) {
        echo "fail {$phrase} got {$fmt} expected {$expect}\n";
        exit(1);
    }
}
$dt = new DateTimeImmutable('2024-01-31 12:00:00');
$m = $dt->modify('next week Monday');
if (false === $m || $m->format('Y-m-d H:i:s') !== '2024-02-05 00:00:00') {
    echo "fail modify next week Monday\n";
    exit(1);
}
echo "ok\n";
--EXPECT--
ok
