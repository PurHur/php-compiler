--TEST--
intlcal_* weekend/bounds/wall-time/error procedural aliases (#20895)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlCalendar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$names = [
    'intlcal_is_weekend',
    'intlcal_get_actual_minimum',
    'intlcal_get_actual_maximum',
    'intlcal_get_least_maximum',
    'intlcal_get_greatest_minimum',
    'intlcal_get_day_of_week_type',
    'intlcal_get_weekend_transition',
    'intlcal_get_repeated_wall_time_option',
    'intlcal_set_repeated_wall_time_option',
    'intlcal_get_skipped_wall_time_option',
    'intlcal_set_skipped_wall_time_option',
    'intlcal_get_error_code',
    'intlcal_get_error_message',
];
foreach ($names as $name) {
    echo $name, '=', function_exists($name) ? 'yes' : 'no', "\n";
}

$c = intlcal_create_instance('UTC', 'en_US');
intlcal_set_time($c, 1705320000000.0); // 2024-01-15 Monday

echo 'isWeekend_mon=', (int) intlcal_is_weekend($c), "\n";
echo 'isWeekend_sat=', (int) intlcal_is_weekend($c, 1705795200000.0), "\n";
echo 'actual_min_dom=', intlcal_get_actual_minimum($c, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'actual_max_dom=', intlcal_get_actual_maximum($c, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'least_max_dom=', intlcal_get_least_maximum($c, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'greatest_min_dom=', intlcal_get_greatest_minimum($c, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'dow_type_sat=', intlcal_get_day_of_week_type($c, IntlCalendar::DOW_SATURDAY), "\n";
echo 'dow_type_mon=', intlcal_get_day_of_week_type($c, IntlCalendar::DOW_MONDAY), "\n";
echo 'weekend_trans_sat=', intlcal_get_weekend_transition($c, IntlCalendar::DOW_SATURDAY), "\n";

intlcal_set_repeated_wall_time_option($c, IntlCalendar::WALLTIME_FIRST);
echo 'repeated=', intlcal_get_repeated_wall_time_option($c), "\n";
intlcal_set_skipped_wall_time_option($c, IntlCalendar::WALLTIME_NEXT_VALID);
echo 'skipped=', intlcal_get_skipped_wall_time_option($c), "\n";

echo 'err_code=', intlcal_get_error_code($c), "\n";
echo 'err_msg=', intlcal_get_error_message($c), "\n";
?>
--EXPECT--
intlcal_is_weekend=yes
intlcal_get_actual_minimum=yes
intlcal_get_actual_maximum=yes
intlcal_get_least_maximum=yes
intlcal_get_greatest_minimum=yes
intlcal_get_day_of_week_type=yes
intlcal_get_weekend_transition=yes
intlcal_get_repeated_wall_time_option=yes
intlcal_set_repeated_wall_time_option=yes
intlcal_get_skipped_wall_time_option=yes
intlcal_set_skipped_wall_time_option=yes
intlcal_get_error_code=yes
intlcal_get_error_message=yes
isWeekend_mon=0
isWeekend_sat=1
actual_min_dom=1
actual_max_dom=31
least_max_dom=28
greatest_min_dom=1
dow_type_sat=1
dow_type_mon=0
weekend_trans_sat=0
repeated=1
skipped=2
err_code=0
err_msg=U_ZERO_ERROR
