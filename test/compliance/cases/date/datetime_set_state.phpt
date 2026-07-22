--TEST--
date DateTime*::__set_state Zend wire + var_export round-trip (ext/date/php_date.c, #22407)
--FILE--
<?php
declare(strict_types=1);
foreach (['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod'] as $c) {
    echo $c, ' __set_state=', method_exists($c, '__set_state') ? '1' : '0', "\n";
}
$e = DateTimeImmutable::__set_state([
    'date' => '2020-01-02 03:04:05.000000',
    'timezone_type' => 3,
    'timezone' => 'UTC',
]);
echo 'format=', $e->format('c'), "\n";
$tz = DateTimeZone::__set_state(['timezone_type' => 3, 'timezone' => 'UTC']);
echo 'tz=', $tz->getName(), "\n";
$di = DateInterval::__set_state([
    'y' => 1, 'm' => 2, 'd' => 3, 'h' => 4, 'i' => 5, 's' => 6,
    'f' => 0.0, 'invert' => 0, 'days' => false, 'from_string' => false,
]);
echo 'interval=', $di->format('%Y-%M-%D'), "\n";
$dt = new DateTimeImmutable('2020-01-02 03:04:05', new DateTimeZone('UTC'));
$round = eval('return '.var_export($dt, true).';');
echo 'eval=', $round->format('c'), "\n";
$dp = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
$dp2 = eval('return '.var_export($dp, true).';');
echo 'period=', iterator_count($dp2), "\n";
?>
--EXPECT--
DateTime __set_state=1
DateTimeImmutable __set_state=1
DateTimeZone __set_state=1
DateInterval __set_state=1
DatePeriod __set_state=1
format=2020-01-02T03:04:05+00:00
tz=UTC
interval=01-02-03
eval=2020-01-02T03:04:05+00:00
period=3
