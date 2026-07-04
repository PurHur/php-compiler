<?php

declare(strict_types=1);

$tz = new DateTimeZone('UTC');
$dt = new DateTime('2026-06-06 12:00:00', $tz);
$tz->getOffset($dt);

$ny = new DateTimeZone('America/New_York');
$dtNy = new DateTime('2026-06-06 12:00:00', $ny);
echo $dtNy->format('c'), "\n";
