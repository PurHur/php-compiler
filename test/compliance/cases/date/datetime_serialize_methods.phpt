--TEST--
date DateTime*::__serialize/__unserialize/__wakeup method table (ext/date/php_date.c, #22596)
--FILE--
<?php
declare(strict_types=1);
foreach (['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod'] as $c) {
    echo $c,
        ' ser=', method_exists($c, '__serialize') ? '1' : '0',
        ' unser=', method_exists($c, '__unserialize') ? '1' : '0',
        ' wake=', method_exists($c, '__wakeup') ? '1' : '0',
        "\n";
    new ReflectionMethod($c, '__serialize');
}
$dt = new DateTime('2020-01-02 03:04:05', new DateTimeZone('UTC'));
$bag = $dt->__serialize();
echo 'keys=', implode(',', array_keys($bag)), "\n";
$dt2 = (new ReflectionClass('DateTime'))->newInstanceWithoutConstructor();
$dt2->__unserialize($bag);
echo 'format=', $dt2->format('c'), "\n";
echo 'round=', unserialize(serialize($dt))->format('c'), "\n";
$dti = new DateTimeImmutable('2020-01-02 03:04:05', new DateTimeZone('UTC'));
echo 'imm=', unserialize(serialize($dti))->format('c'), "\n";
$tz = new DateTimeZone('Europe/Berlin');
$tzBag = $tz->__serialize();
echo 'tzkeys=', implode(',', array_keys($tzBag)), "\n";
echo 'tz=', (unserialize(serialize($tz)))->getName(), "\n";
$di = new DateInterval('P1Y2M3DT4H5M6S');
$di2 = (new ReflectionClass('DateInterval'))->newInstanceWithoutConstructor();
$di2->__unserialize($di->__serialize());
echo 'interval=', $di2->format('%Y-%M-%D'), "\n";
$dp = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
$dpBag = $dp->__serialize();
echo 'period_has_start=', isset($dpBag['start']) ? '1' : '0', "\n";
echo 'period=', iterator_count(unserialize(serialize($dp))), "\n";
try {
    (new ReflectionClass('DateTime'))->newInstanceWithoutConstructor()->__wakeup();
    echo "wakeup_empty=ok\n";
} catch (Error $e) {
    echo 'wakeup_empty=', $e->getMessage(), "\n";
}
?>
--EXPECT--
DateTime ser=1 unser=1 wake=1
DateTimeImmutable ser=1 unser=1 wake=1
DateTimeZone ser=1 unser=1 wake=1
DateInterval ser=1 unser=1 wake=1
DatePeriod ser=1 unser=1 wake=1
keys=date,timezone_type,timezone
format=2020-01-02T03:04:05+00:00
round=2020-01-02T03:04:05+00:00
imm=2020-01-02T03:04:05+00:00
tzkeys=timezone_type,timezone
tz=Europe/Berlin
interval=01-02-03
period_has_start=1
period=3
wakeup_empty=Invalid serialization data for DateTime object
