<?php
$tz = new DateTimeZone('UTC');
$dt = new DateTime('2024-01-01 UTC');
echo 'tz_props=', implode(',', array_map(
    fn(ReflectionProperty $p) => $p->getName(),
    (new ReflectionClass($tz))->getProperties()
)), "\n";
echo 'dt_props=', implode(',', array_map(
    fn(ReflectionProperty $p) => $p->getName(),
    (new ReflectionClass($dt))->getProperties()
)), "\n";
$vars = get_object_vars($tz);
echo 'tz_gov=', implode(',', array_keys($vars)), "\n";
