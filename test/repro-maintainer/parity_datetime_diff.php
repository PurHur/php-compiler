<?php

declare(strict_types=1);

$dt = new DateTime('2026-05-29');
$interval = $dt->diff(new DateTime('2026-06-05'));
echo $interval->days, "\n";
echo $interval->invert ? 'inverted' : 'forward', "\n";

$immutable = new DateTimeImmutable('2026-01-01');
$span = $immutable->diff(new DateTimeImmutable('2026-01-08'));
echo $span->days, "\n";
