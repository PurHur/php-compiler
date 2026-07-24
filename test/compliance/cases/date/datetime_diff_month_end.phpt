--TEST--
date DateTime::diff month-end calendar normalize (ext/date/lib/interval.c, #22849)
--FILE--
<?php
declare(strict_types=1);
foreach ([['2020-01-31', '2020-03-01'], ['2021-01-31', '2021-03-01'], ['2020-01-01', '2020-02-01'], ['2020-01-15', '2020-03-15']] as [$a, $b]) {
    $d = (new DateTime($a))->diff(new DateTime($b));
    echo "$a->$b m={$d->m} d={$d->d} days={$d->days}\n";
}
--EXPECT--
2020-01-31->2020-03-01 m=0 d=30 days=30
2021-01-31->2021-03-01 m=0 d=29 days=29
2020-01-01->2020-02-01 m=1 d=0 days=31
2020-01-15->2020-03-15 m=2 d=0 days=60
