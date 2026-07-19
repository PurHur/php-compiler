--TEST--
intlcal_* week/locale/daylight/lenient/keyword procedural aliases (#20896)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlCalendar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$names = [
    'intlcal_get_locale',
    'intlcal_is_lenient',
    'intlcal_set_lenient',
    'intlcal_in_daylight_time',
    'intlcal_get_first_day_of_week',
    'intlcal_set_first_day_of_week',
    'intlcal_get_minimal_days_in_first_week',
    'intlcal_set_minimal_days_in_first_week',
    'intlcal_get_keyword_values_for_locale',
    'intlcal_is_equivalent_to',
];
foreach ($names as $name) {
    echo $name, '=', function_exists($name) ? 'yes' : 'no', "\n";
}

$c = intlcal_create_instance('Europe/Paris', 'fr_FR');
intlcal_set_time($c, (float) (gmmktime(12, 0, 0, 7, 15, 2024) * 1000));
echo 'dst=', (int) intlcal_in_daylight_time($c), "\n";
echo 'locale=', intlcal_get_locale($c, 1), "\n";
echo 'first=', intlcal_get_first_day_of_week($c), ' minDays=', intlcal_get_minimal_days_in_first_week($c), "\n";
intlcal_set_lenient($c, false);
echo 'lenient=', (int) intlcal_is_lenient($c), "\n";
intlcal_set_first_day_of_week($c, IntlCalendar::DOW_SUNDAY);
echo 'first2=', intlcal_get_first_day_of_week($c), "\n";
intlcal_set_minimal_days_in_first_week($c, 1);
echo 'minDays2=', intlcal_get_minimal_days_in_first_week($c), "\n";

$vals = intlcal_get_keyword_values_for_locale('calendar', 'en_US', true);
$has = false;
foreach ($vals as $v) {
    if ($v === 'gregorian') {
        $has = true;
        break;
    }
}
echo 'greg=', $has ? '1' : '0', "\n";

$a = intlcal_create_instance('UTC', 'en_US');
$b = intlcal_create_instance('UTC', 'en_US');
$c2 = intlcal_create_instance('America/New_York', 'en_US');
echo 'equiv_same=', (int) intlcal_is_equivalent_to($a, $b), "\n";
echo 'equiv_diff=', (int) intlcal_is_equivalent_to($a, $c2), "\n";
?>
--EXPECT--
intlcal_get_locale=yes
intlcal_is_lenient=yes
intlcal_set_lenient=yes
intlcal_in_daylight_time=yes
intlcal_get_first_day_of_week=yes
intlcal_set_first_day_of_week=yes
intlcal_get_minimal_days_in_first_week=yes
intlcal_set_minimal_days_in_first_week=yes
intlcal_get_keyword_values_for_locale=yes
intlcal_is_equivalent_to=yes
dst=1
locale=fr_FR
first=2 minDays=4
lenient=0
first2=1
minDays2=1
greg=1
equiv_same=1
equiv_diff=0
