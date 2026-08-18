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
$spec = new DateInterval('P1M10D');
echo 'spec days=', var_export($spec->days, true), ' a=', $spec->format('%a'), "\n";
