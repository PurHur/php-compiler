<?php
// #34614 — AOT unserialize(serialize(DateTime*)) named zone: format('c') / getOffset match Zend.
declare(strict_types=1);

$u = unserialize(serialize(new DateTime('2020-01-15 12:30:45', new DateTimeZone('Europe/Berlin'))));
echo $u->format('c'), ' ', $u->getOffset(), PHP_EOL;

$ui = unserialize(serialize(new DateTimeImmutable('2020-01-15 12:30:45', new DateTimeZone('Europe/Berlin'))));
echo $ui->format('c'), ' ', $ui->getOffset(), PHP_EOL;

// Assigned serialize path (wire on local string).
$d = new DateTime('2020-01-15 12:30:45', new DateTimeZone('America/New_York'));
$s = serialize($d);
$u2 = unserialize($s);
echo $u2->format('c'), ' ', $u2->getOffset(), PHP_EOL;
