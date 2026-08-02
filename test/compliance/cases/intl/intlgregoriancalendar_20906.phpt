--TEST--
IntlGregorianCalendar + isLeapYear/getGregorianChange (#20906; createFromDate* → #26745 forward83)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlGregorianCalendar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'IntlGregorianCalendar=', class_exists('IntlGregorianCalendar') ? 'yes' : 'no', "\n";
$c = IntlCalendar::createInstance('UTC', 'en_US');
echo 'createInstance_class=', get_class($c), "\n";
echo 'createInstance_type=', $c->getType(), "\n";
echo 'instanceof=', ($c instanceof IntlGregorianCalendar) ? '1' : '0', "\n";

$g = new IntlGregorianCalendar(2024, 1, 29);
echo 'leap2024=', $g->isLeapYear(2024) ? '1' : '0', "\n";
echo 'leap2023=', $g->isLeapYear(2023) ? '1' : '0', "\n";
echo 'change=', $g->getGregorianChange(), "\n";
$g->setGregorianChange(123456789000.0);
echo 'change2=', $g->getGregorianChange(), "\n";

$g2 = new IntlGregorianCalendar('UTC', 'en_US');
echo 'tz=', $g2->getTimeZone()->getID(), "\n";
echo 'tz_type=', $g2->getType(), "\n";

foreach ([
    'intlgregcal_create_instance',
    'intlgregcal_is_leap_year',
    'intlgregcal_get_gregorian_change',
    'intlgregcal_set_gregorian_change',
    'intlgregcal_create_from_date',
    'intlgregcal_create_from_date_time',
] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', "\n";
}
echo 'proc_leap=', intlgregcal_is_leap_year($g, 2024) ? '1' : '0', "\n";
// createFromDate* are OO-only (PHP 8.3+); no procedural aliases in php-src (#26745)
echo 'createFromDate=', method_exists('IntlGregorianCalendar', 'createFromDate') ? 'yes' : 'no', "\n";
?>
--EXPECT--
IntlGregorianCalendar=yes
createInstance_class=IntlGregorianCalendar
createInstance_type=gregorian
instanceof=1
leap2024=1
leap2023=0
change=-12219292800000
change2=123456789000
tz=UTC
tz_type=gregorian
intlgregcal_create_instance=yes
intlgregcal_is_leap_year=yes
intlgregcal_get_gregorian_change=yes
intlgregcal_set_gregorian_change=yes
intlgregcal_create_from_date=no
intlgregcal_create_from_date_time=no
proc_leap=1
createFromDate=no
