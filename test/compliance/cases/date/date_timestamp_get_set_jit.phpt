--TEST--
JIT: date_timestamp_get/set + DateTime(Immutable)::getTimestamp/setTimestamp (#30745)
--FILE--
<?php
$dt = date_create('2020-01-02T03:04:05+00:00');
echo date_timestamp_get($dt), "\n";
date_timestamp_set($dt, 1577836800);
echo date_format($dt, 'Y-m-d'), "\n";
echo (new DateTime('@100'))->getTimestamp(), "\n";
$mut = new DateTime('2020-01-01');
$mut->setTimestamp(1577836800);
echo $mut->format('Y-m-d'), "\n";
$imm = new DateTimeImmutable('@50');
echo $imm->getTimestamp(), "\n";
$imm2 = $imm->setTimestamp(1577836800);
echo $imm->getTimestamp(), "\n";
echo $imm2->format('Y-m-d'), "\n";
?>
--EXPECT--
1577934245
2020-01-01
100
2020-01-01
50
50
2020-01-01
