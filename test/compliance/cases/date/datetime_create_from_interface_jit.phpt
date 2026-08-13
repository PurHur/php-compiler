--TEST--
JIT: DateTime(Immutable) createFromInterface/Immutable/Mutable (#30762)
--FILE--
<?php
$im = new DateTimeImmutable('2020-01-15', new DateTimeZone('UTC'));
$m = DateTime::createFromInterface($im);
echo $m->format('Y-m-d'), ' ', get_class($m), "\n";
$m2 = DateTime::createFromImmutable($im);
echo $m2->format('Y-m-d'), ' ', get_class($m2), "\n";
$mut = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$im2 = DateTimeImmutable::createFromMutable($mut);
echo $im2->format('Y-m-d'), ' ', get_class($im2), "\n";
$im3 = DateTimeImmutable::createFromInterface($mut);
echo $im3->format('Y-m-d'), ' ', get_class($im3), "\n";
?>
--EXPECT--
2020-01-15 DateTime
2020-01-15 DateTime
2020-01-15 DateTimeImmutable
2020-01-15 DateTimeImmutable
