--TEST--
stdlib strtotime() — natural-language relative phrases (#15058, ext/standard/parsdate.c)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00');
$cases = [
    'last monday of July 2020' => 1595808000,
    'midnight' => 1579046400,
    'noon' => 1579089600,
    'tomorrow 12:30pm' => 1579177800,
    '+1 week 2 days 4 hours 2 seconds' => 1579881602,
    'next Thursday' => 1579132800,
];
foreach ($cases as $phrase => $expected) {
    if (strtotime($phrase, $base) !== $expected) {
        echo "fail\n";
        exit(1);
    }
}
echo "ok\n";
--EXPECT--
ok
