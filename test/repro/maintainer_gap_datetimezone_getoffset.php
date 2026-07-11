<?php

declare(strict_types=1);

$tz = new DateTimeZone('UTC');
echo 'has_getOffset='.(method_exists($tz, 'getOffset') ? 'true' : 'false'), "\n";
$dt = new DateTime('2026-06-06 12:00:00', $tz);
echo 'offset=', $tz->getOffset($dt), "\n";

$ny = new DateTimeZone('America/New_York');
$dtNy = new DateTime('2026-06-06 12:00:00', $ny);
echo $ny->getName(), "\n";
echo 'offset=', $ny->getOffset($dtNy), "\n";
