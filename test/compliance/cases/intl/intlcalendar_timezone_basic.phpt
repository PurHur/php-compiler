--TEST--
IntlCalendar / IntlTimeZone Gregorian subset without ext/intl (#6151)
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'calendar=', (int) class_exists('IntlCalendar', false), "\n";
echo 'timezone=', (int) class_exists('IntlTimeZone', false), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";

$tz = IntlTimeZone::createTimeZone('UTC');
echo 'tz_ok=', (int) ($tz instanceof IntlTimeZone), "\n";
echo 'tz_id=', $tz->getID(), "\n";

$cal = IntlCalendar::createInstance('UTC', 'en_US');
echo 'cal_ok=', (int) ($cal instanceof IntlCalendar), "\n";
$cal->setTime(1579096800000.0); // 2020-01-15 14:00:00 UTC
echo 'year=', $cal->get(IntlCalendar::FIELD_YEAR), "\n";
echo 'month=', $cal->get(IntlCalendar::FIELD_MONTH), "\n"; // 0-based
echo 'dom=', $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'hour=', $cal->get(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";

$cal->set(IntlCalendar::FIELD_YEAR, 2021);
$cal->set(IntlCalendar::FIELD_MONTH, 5); // June
$cal->set(IntlCalendar::FIELD_DAY_OF_MONTH, 6);
echo 'after_set=', $cal->get(IntlCalendar::FIELD_YEAR), '-',
    $cal->get(IntlCalendar::FIELD_MONTH) + 1, '-',
    $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$tz2 = $cal->getTimeZone();
echo 'cal_tz=', $tz2->getID(), "\n";
?>
--EXPECT--
intl_loaded=1
calendar=1
timezone=1
collator=1
tz_ok=1
tz_id=UTC
cal_ok=1
year=2020
month=0
dom=15
hour=14
after_set=2021-6-6
cal_tz=UTC
