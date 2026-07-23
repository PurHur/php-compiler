<?php
declare(strict_types=1);
// Repro #22596 — Date*::__serialize / __unserialize / __wakeup method table
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
$tz = new DateTimeZone('Europe/Berlin');
echo 'tz=', (unserialize(serialize($tz)))->getName(), "\n";
$di = new DateInterval('P1Y2M3DT4H5M6S');
$di2 = (new ReflectionClass('DateInterval'))->newInstanceWithoutConstructor();
$di2->__unserialize($di->__serialize());
echo 'interval=', $di2->format('%Y-%M-%D'), "\n";
$dp = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
echo 'period=', iterator_count(unserialize(serialize($dp))), "\n";
