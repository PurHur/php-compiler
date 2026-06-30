--TEST--
stdlib strtotime()/date_create() weekday-relative modifiers (#14151, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$base = strtotime('2026-06-30 12:00:00'); // Tuesday
$cases = [
    'last monday' => '2026-06-29',
    'next monday' => '2026-07-06',
    'monday' => '2026-07-06',
    'previous monday' => '2026-06-29',
    'this monday' => '2026-07-06',
];
foreach ($cases as $input => $expected) {
    $ts = strtotime($input, $base);
    echo $input, '=', date('Y-m-d', $ts), "\n";
}
$mondayBase = strtotime('2026-06-29 12:00:00');
echo 'monday-on-monday=', date('Y-m-d', strtotime('monday', $mondayBase)), "\n";
echo 'this-monday-on-monday=', date('Y-m-d', strtotime('this monday', $mondayBase)), "\n";
$dt = date_create('next monday');
echo $dt instanceof DateTimeInterface ? 'create-ok' : 'create-fail', "\n";
--EXPECT--
last monday=2026-06-29
next monday=2026-07-06
monday=2026-07-06
previous monday=2026-06-29
this monday=2026-07-06
monday-on-monday=2026-06-29
this-monday-on-monday=2026-06-29
create-ok
