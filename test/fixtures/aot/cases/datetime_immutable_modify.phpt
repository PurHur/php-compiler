--TEST--
AOT: DateTimeImmutable::modify('+1 day') leaves original unchanged (#26789)
--FILE--
<?php
$d = new DateTimeImmutable('2020-01-01');
$d2 = $d->modify('+1 day');
echo $d->format('Y-m-d'), ',', $d2->format('Y-m-d'), "\n";
--EXPECT--
2020-01-01,2020-01-02
