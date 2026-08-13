--TEST--
AOT: DateTime(Immutable)::getOffset() UTC + named TZ; DateTimeZone::getOffset still 0 (#30761)
--FILE--
<?php
$d = new DateTime('2020-01-15 12:00:00', new DateTimeZone('UTC'));
echo $d->getOffset(), "\n";
$london = new DateTime('2020-01-15 12:00:00', new DateTimeZone('Europe/London'));
echo $london->getOffset(), "\n";
$ny = new DateTime('2020-01-15 12:00:00', new DateTimeZone('America/New_York'));
echo $ny->getOffset(), "\n";
$imm = new DateTimeImmutable('2020-01-15 12:00:00', new DateTimeZone('UTC'));
echo $imm->getOffset(), "\n";
$z = new DateTimeZone('UTC');
echo $z->getOffset($d), "\n";
?>
--EXPECT--
0
0
-18000
0
0
