--TEST--
JIT: date_timezone_get/set + DateTime(Immutable)::getTimezone (#30746)
--FILE--
<?php
$dt = date_create('2020-01-01', new DateTimeZone('UTC'));
echo date_timezone_get($dt)->getName(), "\n";
date_timezone_set($dt, new DateTimeZone('Europe/Berlin'));
echo date_timezone_get($dt)->getName(), "\n";
echo (new DateTime('2020-01-01', new DateTimeZone('UTC')))->getTimezone()->getName(), "\n";
$mut = new DateTime('2020-01-01', new DateTimeZone('UTC'));
$mut->setTimezone(new DateTimeZone('Europe/Berlin'));
echo $mut->getTimezone()->getName(), "\n";
$imm = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));
echo $imm->getTimezone()->getName(), "\n";
?>
--EXPECT--
UTC
Europe/Berlin
UTC
Europe/Berlin
UTC
