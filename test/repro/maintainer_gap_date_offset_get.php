<?php
// Issue #11876 — date_offset_get() procedural wrapper (ext/date/php_date.c).
echo function_exists('date_offset_get') ? "exists\n" : "missing\n";
$dt = new DateTime('2020-06-01', new DateTimeZone('America/New_York'));
echo date_offset_get($dt), "\n";
$tz = new DateTimeZone('America/New_York');
$dt2 = new DateTime('2020-06-01', $tz);
echo $tz->getOffset($dt2), "\n";
