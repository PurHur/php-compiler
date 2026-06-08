<?php
$tz = new DateTimeZone('UTC');
if (!method_exists($tz, 'getName')) {
    fwrite(STDERR, "MISSING getName\n");
    exit(1);
}
echo $tz->getName(), "\n";
$dt = new DateTime('2026-06-06 12:00:00', $tz);
echo $tz->getOffset($dt), "\n";
var_export($tz->getLocation());
echo "\n";
