--TEST--
DateTime NEW with variable DateTimeZone after prior NEW (#15996)
--FILE--
<?php
declare(strict_types=1);

$tz = new DateTimeZone('UTC');
$dt = new DateTime('2020-01-01 12:00:00', $tz);
echo $dt->format('c'), "\n";

$tz->getOffset($dt);

$ny = new DateTimeZone('America/New_York');
$dtNy = new DateTime('2026-06-06 12:00:00', $ny);
echo $dtNy->format('c'), "\n";
--EXPECT--
2020-01-01T12:00:00+00:00
2026-06-06T12:00:00-04:00
