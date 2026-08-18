--TEST--
stdlib DateTime::diff DateInterval::$days / format('%a') calendar days across Jan/Feb (#32062, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL);
$tz = new DateTimeZone('UTC');
foreach ([
    ['2020-01-31', '2020-03-01'],
    ['2019-12-31', '2020-01-02'],
    ['2020-02-28', '2020-03-02'],
    ['2020-06-01', '2020-06-11'],
] as [$from, $to]) {
    $a = new DateTime($from, $tz);
    $b = new DateTime($to, $tz);
    $d = $a->diff($b);
    $proc = date_diff($a, $b);
    echo $from, '->', $to, ' d=', $d->d, ' days=', $d->days, ' a=', $d->format('%a'),
        ' date_diff_days=', $proc->days, "\n";
}
$imm = (new DateTimeImmutable('2020-01-31', $tz))->diff(new DateTimeImmutable('2020-03-01', $tz));
echo 'immutable days=', $imm->days, "\n";
$spec = new DateInterval('P1M10D');
echo 'spec days=', var_export($spec->days, true), ' a=', $spec->format('%a'), "\n";
?>
--EXPECT--
2020-01-31->2020-03-01 d=30 days=30 a=30 date_diff_days=30
2019-12-31->2020-01-02 d=2 days=2 a=2 date_diff_days=2
2020-02-28->2020-03-02 d=3 days=3 a=3 date_diff_days=3
2020-06-01->2020-06-11 d=10 days=10 a=10 date_diff_days=10
immutable days=30
spec days=false a=(unknown)
