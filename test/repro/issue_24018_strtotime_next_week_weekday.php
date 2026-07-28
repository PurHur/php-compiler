<?php
declare(strict_types=1);
// Repro #24018 — strtotime / DateTimeImmutable::modify "next week Monday"
// (same as "Monday next week"; week token first). php-src ext/date/lib/parse_date.re
date_default_timezone_set('UTC');
$baseTs = strtotime('2024-01-31 12:00:00');
$cases = [
    'next week Monday' => '2024-02-05 00:00:00',
    'Monday next week' => '2024-02-05 00:00:00',
    'last week Friday' => '2024-01-26 00:00:00',
    'this week Wednesday' => '2024-01-31 00:00:00',
    '2020-01-15 next week Monday' => '2020-01-20 00:00:00',
    '2020-01-15 Monday next week' => '2020-01-20 00:00:00',
];
$fail = 0;
foreach ($cases as $phrase => $expect) {
    $t = strtotime($phrase, $baseTs);
    $got = false === $t ? 'false' : date('Y-m-d H:i:s', $t);
    if ($got !== $expect) {
        echo "strtotime FAIL {$phrase} => {$got} want {$expect}\n";
        $fail++;
    }
}
$base = new DateTimeImmutable('2024-01-31 12:00:00');
foreach ([
    'next week Monday' => '2024-02-05 00:00:00',
    'Monday next week' => '2024-02-05 00:00:00',
    'last week Friday' => '2024-01-26 00:00:00',
] as $phrase => $expect) {
    $r = @$base->modify($phrase);
    $got = false === $r ? 'false' : $r->format('Y-m-d H:i:s');
    if ($got !== $expect) {
        echo "modify FAIL {$phrase} => {$got} want {$expect}\n";
        $fail++;
    }
}
if ($fail > 0) {
    exit(1);
}
echo "ok\n";
