<?php
// #20729 — IntlDateFormatter parse / localtime / getTimeZone / setTimeZone / errors
$m = ['format', 'parse', 'parseToCalendar', 'localtime', 'getTimeZone', 'setTimeZone', 'getErrorCode', 'getErrorMessage'];
foreach ($m as $n) {
    echo $n, '=', method_exists(IntlDateFormatter::class, $n) ? '1' : '0', PHP_EOL;
}

$f = IntlDateFormatter::create('en_US', -1, -1, 'UTC', IntlDateFormatter::GREGORIAN, 'yyyy-MM-dd');
echo 'parse=', var_export($f->parse('2024-06-15'), true), PHP_EOL;
echo 'roundtrip=', $f->format($f->parse('2024-06-15')), PHP_EOL;

$lt = $f->localtime('2024-06-15');
echo 'lt_y=', $lt['tm_year'] + 1900, ' lt_m=', $lt['tm_mon'] + 1, ' lt_d=', $lt['tm_mday'], ' lt_h=', $lt['tm_hour'], PHP_EOL;

echo 'bad_prefix=', var_export($f->parse('xx2024-06-15'), true), PHP_EOL;
$pos = 2;
echo 'from_offset=', var_export($f->parse('xx2024-06-15', $pos), true), PHP_EOL;

echo 'bad=', var_export($f->parse('not-a-date'), true), PHP_EOL;
echo 'err=', $f->getErrorCode(), ' msg=', $f->getErrorMessage(), PHP_EOL;

$tz = $f->getTimeZone();
echo 'tz=', $tz->getID(), PHP_EOL;
$f->setTimeZone('Europe/Berlin');
echo 'tz2=', $f->getTimeZone()->getID(), PHP_EOL;

$f2 = IntlDateFormatter::create('en_US', -1, -1, 'UTC', 1, 'yyyy-MM-dd HH:mm:ss');
echo 'parse_dt=', var_export($f2->parse('2024-06-15 12:34:56'), true), PHP_EOL;
