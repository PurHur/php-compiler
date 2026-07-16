--TEST--
stdlib DatePeriod::createFromISO8601String() INCLUDE_END_DATE / combined flags (#19737, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$spec = '2020-01-01/P1D/2020-01-03';

$out = [];
foreach (DatePeriod::createFromISO8601String($spec, 0) as $d) {
    $out[] = $d->format('Y-m-d');
}
echo 'default=', implode(',', $out), "\n";

$outInc = [];
foreach (DatePeriod::createFromISO8601String($spec, DatePeriod::INCLUDE_END_DATE) as $d) {
    $outInc[] = $d->format('Y-m-d');
}
echo 'include_end=', implode(',', $outInc), "\n";

$outEx = [];
foreach (DatePeriod::createFromISO8601String($spec, DatePeriod::EXCLUDE_START_DATE) as $d) {
    $outEx[] = $d->format('Y-m-d');
}
echo 'exclude_start=', implode(',', $outEx), "\n";

$outBoth = [];
foreach (DatePeriod::createFromISO8601String(
    '2020-01-01/P1D/2020-01-04',
    DatePeriod::EXCLUDE_START_DATE | DatePeriod::INCLUDE_END_DATE
) as $d) {
    $outBoth[] = $d->format('Y-m-d');
}
echo 'flags=', implode(',', $outBoth), "\n";

$outR = [];
foreach (DatePeriod::createFromISO8601String('R2/2020-01-01/P1D', DatePeriod::INCLUDE_END_DATE) as $d) {
    $outR[] = $d->format('Y-m-d');
}
echo 'R2_include_end=', implode(',', $outR), "\n";
--EXPECT--
default=2020-01-01,2020-01-02
include_end=2020-01-01,2020-01-02,2020-01-03
exclude_start=2020-01-02
flags=2020-01-02,2020-01-03,2020-01-04
R2_include_end=2020-01-01,2020-01-02,2020-01-03
