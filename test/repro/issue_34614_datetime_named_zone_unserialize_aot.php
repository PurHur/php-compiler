<?php
// #34614 — AOT named-zone DateTime(Immutable) serialize→unserialize must keep offset/format.
declare(strict_types=1);

$dt = new DateTime('2020-01-15 12:30:45', new DateTimeZone('Europe/Berlin'));
$u = unserialize(serialize($dt));
echo $u->format('c'), ' ', $u->getOffset(), ' ', $u->getTimezone()->getName(), PHP_EOL;

$dti = new DateTimeImmutable('2020-01-15 12:30:45', new DateTimeZone('Europe/Berlin'));
$ui = unserialize(serialize($dti));
echo $ui->format('c'), ' ', $ui->getOffset(), ' ', $ui->getTimezone()->getName(), PHP_EOL;

// UTC must stay green (peer #34594).
$utc = unserialize(serialize(new DateTime('2020-01-15 12:30:45', new DateTimeZone('UTC'))));
echo $utc->format('c'), ' ', $utc->getOffset(), PHP_EOL;
