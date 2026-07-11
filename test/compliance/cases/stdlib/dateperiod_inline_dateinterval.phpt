--TEST--
stdlib DatePeriod inline DateInterval ctor arg + foreach (#14483, ext/date/php_date.c)
--FILE--
<?php
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');
$out = '';
foreach (new DatePeriod($s, new DateInterval('P1D'), $e) as $d) {
    $out .= $d->format('Y-m-d').' ';
}
echo $out, "\n";
echo 'count=', iterator_count(new DatePeriod($s, new DateInterval('P1D'), $e)), "\n";
--EXPECT--
2020-01-01 2020-01-02 
count=2
