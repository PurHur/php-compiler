--TEST--
AOT: DateTime{,Immutable}::add/sub with runtime DateInterval P1M (#33781)
--FILE--
<?php
$i = new DateInterval('P1M');
$d = new DateTimeImmutable('2020-01-15', new DateTimeZone('UTC'));
echo $d->add($i)->format('Y-m-d'), "\n";
$m = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$m->add($i);
echo $m->format('Y-m-d'), "\n";
$m->sub($i);
echo $m->format('Y-m-d'), "\n";
?>
--EXPECT--
2020-02-15
2020-02-15
2020-01-15
