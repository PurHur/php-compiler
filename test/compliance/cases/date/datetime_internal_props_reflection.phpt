--TEST--
date DateTime/DateTimeZone Reflection omits internal __dt_* storage (#26155, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$tz = new DateTimeZone('UTC');
$dt = new DateTime('2024-01-01 UTC');
$dti = new DateTimeImmutable('2024-01-01 UTC');
echo 'tz_props=', implode(',', array_map(
    static fn (ReflectionProperty $p): string => $p->getName(),
    (new ReflectionClass($tz))->getProperties()
)), "\n";
echo 'dt_props=', implode(',', array_map(
    static fn (ReflectionProperty $p): string => $p->getName(),
    (new ReflectionClass($dt))->getProperties()
)), "\n";
echo 'dti_props=', implode(',', array_map(
    static fn (ReflectionProperty $p): string => $p->getName(),
    (new ReflectionClass($dti))->getProperties()
)), "\n";
echo 'tz_name=', $tz->getName(), "\n";
echo 'dt_fmt=', $dt->format('Y-m-d'), "\n";
echo 'dti_fmt=', $dti->format('Y-m-d'), "\n";
?>
--EXPECT--
tz_props=
dt_props=
dti_props=
tz_name=UTC
dt_fmt=2024-01-01
dti_fmt=2024-01-01
