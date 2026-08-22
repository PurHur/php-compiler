--TEST--
AOT: DateTime{,Immutable}::add/sub with runtime DateInterval (#33781)
--FILE--
<?php
$i = new DateInterval('P1M');
echo (new DateTimeImmutable('2020-01-15'))->add($i)->format('Y-m-d'), "\n";
$i2 = new DateInterval('P1D');
$d = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$d->add($i2);
echo $d->format('Y-m-d'), "\n";
?>
--EXPECT--
2020-02-15
2020-01-16
