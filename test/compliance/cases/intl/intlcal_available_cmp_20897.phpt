--TEST--
IntlCalendar::getAvailableLocales + intlcal_before/after/set_time_zone/min/max (#20897)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlCalendar available-locales/procs withheld until extension_loaded(\'intl\') (#19670/#20897)';
}
?>
--FILE--
<?php
echo 'oop_available=', method_exists('IntlCalendar', 'getAvailableLocales') ? 'yes' : 'no', "\n";
$names = [
    'intlcal_get_available_locales',
    'intlcal_before',
    'intlcal_after',
    'intlcal_set_time_zone',
    'intlcal_get_minimum',
    'intlcal_get_maximum',
];
foreach ($names as $name) {
    echo $name, '=', function_exists($name) ? 'yes' : 'no', "\n";
}

$locales = IntlCalendar::getAvailableLocales();
echo 'oop_count=', is_array($locales) && count($locales) > 0 ? 'ok' : 'bad', "\n";
echo 'oop_first=', is_string($locales[0] ?? null) ? 'str' : 'bad', "\n";

$procLocales = intlcal_get_available_locales();
echo 'proc_count=', is_array($procLocales) && count($procLocales) > 0 ? 'ok' : 'bad', "\n";
echo 'proc_match=', count($locales) === count($procLocales) ? 'yes' : 'no', "\n";

$a = intlcal_create_instance('UTC', 'en_US');
$b = intlcal_create_instance('UTC', 'en_US');
intlcal_set_time($a, 1705320000000.0); // 2024-01-15
intlcal_set_time($b, 1705406400000.0); // 2024-01-16
echo 'before=', (int) intlcal_before($a, $b), "\n";
echo 'after=', (int) intlcal_after($b, $a), "\n";
echo 'not_before=', (int) intlcal_before($b, $a), "\n";

echo 'min_dom=', intlcal_get_minimum($a, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
echo 'max_dom=', intlcal_get_maximum($a, IntlCalendar::FIELD_DAY_OF_MONTH), "\n";

intlcal_set_time_zone($a, 'Europe/Berlin');
$tz = intlcal_get_time_zone($a);
echo 'setTz=', $tz->getID(), "\n";
?>
--EXPECT--
oop_available=yes
intlcal_get_available_locales=yes
intlcal_before=yes
intlcal_after=yes
intlcal_set_time_zone=yes
intlcal_get_minimum=yes
intlcal_get_maximum=yes
oop_count=ok
oop_first=str
proc_count=ok
proc_match=yes
before=1
after=1
not_before=0
min_dom=1
max_dom=31
setTz=Europe/Berlin
