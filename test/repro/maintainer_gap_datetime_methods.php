<?php

declare(strict_types=1);

/**
 * Issue #10946 — DateTime::add/sub/getTimezone/setTimestamp VM parity.
 */

$dt = new DateTime('2020-01-01', new DateTimeZone('UTC'));

$tz = $dt->getTimezone();
echo 'getTimezone=', $tz->getName(), PHP_EOL;

$dt->add(new DateInterval('P1D'));
echo 'add=', $dt->format('Y-m-d'), PHP_EOL;

$dt->sub(new DateInterval('P1D'));
echo 'sub=', $dt->format('Y-m-d'), PHP_EOL;

$dt->setTimestamp(86400);
echo 'setTimestamp=', $dt->getTimestamp(), PHP_EOL;

$immutable = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));
$next = $immutable->add(new DateInterval('P1D'));
echo 'immutable_add=', $next->format('Y-m-d'), PHP_EOL;
echo 'immutable_unchanged=', $immutable->format('Y-m-d'), PHP_EOL;
