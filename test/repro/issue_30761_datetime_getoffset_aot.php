<?php
// DateTime::getOffset / DateTimeImmutable::getOffset under thin AOT (#30761)
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
echo "ok\n";
