--TEST--
IntlCalendar getType/add/roll/clear/equals/toDateTime/fromDateTime/getNow (#20756)
--FILE--
<?php
$cal = IntlCalendar::createInstance('UTC', 'en_US');
$cal->setTime(1705320000000.0); // 2024-01-15 12:00:00 UTC

echo 'type=', $cal->getType(), "\n";
echo 'getNow_float=', (int) is_float(IntlCalendar::getNow()), "\n";

$cal->add(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
echo 'after_add_day=', $cal->get(IntlCalendar::FIELD_YEAR), '-',
    $cal->get(IntlCalendar::FIELD_MONTH) + 1, '-',
    $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$cal->setTime(1705320000000.0);
$cal->roll(IntlCalendar::FIELD_MONTH, 1);
echo 'after_roll_month=', $cal->get(IntlCalendar::FIELD_YEAR), '-',
    $cal->get(IntlCalendar::FIELD_MONTH) + 1, '-',
    $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$cal->setTime(1706702400000.0); // 2024-01-31 12:00:00 UTC
$cal->roll(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
echo 'roll_dom_wrap=', $cal->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$cal->setTime(1705320000000.0);
$dt = $cal->toDateTime();
echo 'toDateTime=', $dt->format('Y-m-d H:i:s'), "\n";

$from = IntlCalendar::fromDateTime(new DateTime('2024-06-01 00:00:00', new DateTimeZone('UTC')));
echo 'fromDateTime=', $from->get(IntlCalendar::FIELD_YEAR), '-',
    $from->get(IntlCalendar::FIELD_MONTH) + 1, '-',
    $from->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$other = IntlCalendar::createInstance('UTC', 'en_US');
$other->setTime($cal->getTime());
echo 'equals=', (int) $cal->equals($other), "\n";

$cal->clear(IntlCalendar::FIELD_HOUR_OF_DAY);
echo 'isset_hour_after_clear=', (int) $cal->isSet(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";

$cal->clear();
echo 'after_clear_all_year=', $cal->get(IntlCalendar::FIELD_YEAR), "\n";
?>
--EXPECT--
type=gregorian
getNow_float=1
after_add_day=2024-1-16
after_roll_month=2024-2-15
roll_dom_wrap=1
toDateTime=2024-01-15 12:00:00
fromDateTime=2024-6-1
equals=1
isset_hour_after_clear=0
after_clear_all_year=1970
