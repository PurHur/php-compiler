<?php
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');
foreach (new DatePeriod($s, new DateInterval('P1D'), $e) as $d) {
    echo $d->format('Y-m-d'), " ";
}
echo "\n";
echo 'count=', iterator_count(new DatePeriod($s, new DateInterval('P1D'), $e)), "\n";
