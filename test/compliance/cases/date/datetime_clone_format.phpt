--TEST--
date DateTime/DateTimeImmutable clone keeps format/modify/getTimestamp (ext/date/php_date.c, #22892)
--FILE--
<?php
declare(strict_types=1);
$d = new DateTime('2024-01-31');
$c = clone $d;
echo $c->format('Y-m-d'), "\n";
echo (clone $c)->modify('+1 day')->format('Y-m-d'), "\n";
echo (clone $d)->getTimestamp() === $d->getTimestamp() ? "ts_ok\n" : "ts_bad\n";
$i = new DateTimeImmutable('2024-01-31');
echo (clone $i)->format('Y-m-d'), "\n";
echo (clone $i)->modify('+1 day')->format('Y-m-d'), "\n";
--EXPECT--
2024-01-31
2024-02-01
ts_ok
2024-01-31
2024-02-01
