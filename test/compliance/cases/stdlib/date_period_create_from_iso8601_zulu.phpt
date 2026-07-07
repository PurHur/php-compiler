--TEST--
stdlib DatePeriod::createFromISO8601String() — Zulu start/duration/end (#17280, ext/date/php_date.c)
--FILE--
<?php
$p = DatePeriod::createFromISO8601String('2020-01-01T00:00:00Z/P1D/2020-01-05T00:00:00Z');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), "\n";
    break;
}
--EXPECT--
2020-01-01
