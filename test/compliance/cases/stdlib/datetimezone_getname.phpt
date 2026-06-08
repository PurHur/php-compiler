--TEST--
stdlib DateTimeZone getName/getOffset/getLocation (#7131, ext/date/php_datetime.c)
--FILE--
<?php
$tz = new DateTimeZone('UTC');
echo $tz->getName(), "\n";
$dt = new DateTime('2026-06-06 12:00:00', $tz);
echo $tz->getOffset($dt), "\n";
var_export($tz->getLocation());
echo "\n";

$ny = new DateTimeZone('America/New_York');
$dtNy = new DateTime('2026-06-06 12:00:00', $ny);
echo $ny->getName(), "\n";
echo $ny->getOffset($dtNy), "\n";

try {
    $tz->getOffset(new stdClass());
    echo "no-typeerror\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
UTC
0
array (
  'country_code' => '??',
  'latitude' => 0.0,
  'longitude' => 0.0,
  'comments' => '?',
)
America/New_York
-14400
DateTimeZone::getOffset(): Argument #1 ($datetime) must be of type DateTimeInterface, stdClass given
