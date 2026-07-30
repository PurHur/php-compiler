--TEST--
stdlib strtotime()/date_create() ISO week + ordinal day-of-year (#25263, ext/date/lib/parse_date.re)
--FILE--
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
$cases = [
    ['2020W03', '2020-01-13'],
    ['2020-W03', '2020-01-13'],
    ['2020W03-1', '2020-01-13'],
    ['2020-W03-1', '2020-01-13'],
    ['2020W01', '2019-12-30'],
    ['2020-W01-1', '2019-12-30'],
    ['2020013', '2020-01-13'],
    ['2020-013', '2020-01-13'],
    ['2020365', '2020-12-30'],
    ['2020-365', '2020-12-30'],
    ['2020001', '2020-01-01'],
    ['2020366', '2020-12-31'],
    ['2021-366', '2022-01-01'],
    ['2020W53', '2020-12-28'],
    ['2020W03-7', '2020-01-19'],
];
foreach ($cases as [$input, $expected]) {
    $ts = @strtotime($input);
    $got = is_int($ts) ? date('Y-m-d', $ts) : 'false';
    echo $input, '=', $got, ($got === $expected ? '' : ' FAIL want '.$expected), "\n";
}
echo 'bad-week=', var_export(@strtotime('2020W00'), true), "\n";
echo 'bad-day=', var_export(@strtotime('2020370'), true), "\n";
echo 'bad-ord=', var_export(@strtotime('2020-000'), true), "\n";
$dt = date_create('2020-W03-1');
echo 'create=', $dt instanceof DateTimeInterface ? $dt->format('Y-m-d') : 'false', "\n";
--EXPECT--
2020W03=2020-01-13
2020-W03=2020-01-13
2020W03-1=2020-01-13
2020-W03-1=2020-01-13
2020W01=2019-12-30
2020-W01-1=2019-12-30
2020013=2020-01-13
2020-013=2020-01-13
2020365=2020-12-30
2020-365=2020-12-30
2020001=2020-01-01
2020366=2020-12-31
2021-366=2022-01-01
2020W53=2020-12-28
2020W03-7=2020-01-19
bad-week=false
bad-day=false
bad-ord=false
create=2020-01-13
