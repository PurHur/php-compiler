<?php
// Repro for #20756 — IntlCalendar getType/add/roll/clear/equals/toDateTime/fromDateTime/getNow
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

$cal->setTime(1705320000000.0);
$dt = $cal->toDateTime();
echo 'toDateTime=', $dt->format('Y-m-d H:i:s'), ' ', $dt->getTimezone()->getName(), "\n";

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
echo 'methods=',
    (int) method_exists($cal, 'getType'),
    (int) method_exists($cal, 'add'),
    (int) method_exists($cal, 'roll'),
    (int) method_exists($cal, 'clear'),
    (int) method_exists($cal, 'toDateTime'),
    (int) method_exists(IntlCalendar::class, 'fromDateTime'),
    (int) method_exists(IntlCalendar::class, 'getNow'),
    "\n";
