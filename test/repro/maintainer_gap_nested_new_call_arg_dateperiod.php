<?php
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');

$cls = get_class(new DatePeriod($s, new DateInterval('P1D'), $e));
if ('DatePeriod' !== $cls) {
    echo "FAIL: get_class='{$cls}'\n";
    exit(1);
}

$ic = iterator_count(new DatePeriod($s, new DateInterval('P1D'), $e));
if (2 !== $ic) {
    echo "FAIL: iterator_count={$ic}\n";
    exit(1);
}

echo "ok\n";
