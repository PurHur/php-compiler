--TEST--
intlcal_* procedural aliases for IntlCalendar (#20836)
--FILE--
<?php
$names = [
    'intlcal_create_instance',
    'intlcal_get',
    'intlcal_get_type',
    'intlcal_add',
    'intlcal_set',
    'intlcal_get_now',
    'intlcal_set_time',
    'intlcal_get_time',
    'intlcal_roll',
    'intlcal_clear',
    'intlcal_is_set',
    'intlcal_equals',
    'intlcal_to_date_time',
    'intlcal_from_date_time',
    'intlcal_field_difference',
    'intlcal_get_time_zone',
];
foreach ($names as $name) {
    echo $name, '=', function_exists($name) ? 'yes' : 'no', "\n";
}

$cal = intlcal_create_instance('UTC', 'en_US');
echo 'type=', intlcal_get_type($cal), "\n";
intlcal_set_time($cal, 1705320000000.0); // 2024-01-15 12:00:00 UTC
echo 'year=', intlcal_get($cal, IntlCalendar::FIELD_YEAR), "\n";
echo 'month=', intlcal_get($cal, IntlCalendar::FIELD_MONTH) + 1, "\n";
echo 'dom=', intlcal_get($cal, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

intlcal_add($cal, IntlCalendar::FIELD_DAY_OF_MONTH, 1);
echo 'after_add_dom=', intlcal_get($cal, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

intlcal_set_time($cal, 1705320000000.0);
intlcal_set($cal, IntlCalendar::FIELD_YEAR, 2025);
echo 'after_set_year=', intlcal_get($cal, IntlCalendar::FIELD_YEAR), "\n";

$other = intlcal_create_instance('UTC', 'en_US');
intlcal_set_time($other, intlcal_get_time($cal));
echo 'equals=', (int) intlcal_equals($cal, $other), "\n";

$asDateTime = intlcal_to_date_time($cal);
echo 'toDateTime=', $asDateTime->format('Y-m-d'), "\n";

$fromDt = new DateTime('2024-06-01 00:00:00', new DateTimeZone('UTC'));
$from = intlcal_from_date_time($fromDt);
echo 'fromDateTime=', intlcal_get($from, IntlCalendar::FIELD_YEAR), '-',
    intlcal_get($from, IntlCalendar::FIELD_MONTH) + 1, '-',
    intlcal_get($from, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

$now = intlcal_get_now();
echo 'getNow_float=', (int) is_float($now), "\n";
?>
--EXPECT--
intlcal_create_instance=yes
intlcal_get=yes
intlcal_get_type=yes
intlcal_add=yes
intlcal_set=yes
intlcal_get_now=yes
intlcal_set_time=yes
intlcal_get_time=yes
intlcal_roll=yes
intlcal_clear=yes
intlcal_is_set=yes
intlcal_equals=yes
intlcal_to_date_time=yes
intlcal_from_date_time=yes
intlcal_field_difference=yes
intlcal_get_time_zone=yes
type=gregorian
year=2024
month=1
dom=15
after_add_dom=16
after_set_year=2025
equals=1
toDateTime=2025-01-15
fromDateTime=2024-6-1
getNow_float=1
