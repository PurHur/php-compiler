--TEST--
AOT: DatePeriod int $recurrences foreach (#26852)
--FILE--
<?php
$p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
foreach ($p as $d) {
    echo $d->format('Y-m-d'), ' ';
}
echo "\n";
--EXPECT--
2020-01-01 2020-01-02 2020-01-03 
