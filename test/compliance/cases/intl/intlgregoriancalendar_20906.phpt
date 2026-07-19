--TEST--
IntlGregorianCalendar + isLeapYear/getGregorianChange/createFromDate* (#20906)
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

$d = IntlGregorianCalendar::createFromDate(2020, 0, 1);
echo 'fromDate_y=', $d->get(IntlCalendar::FIELD_YEAR), "\n";
echo 'fromDate_m=', $d->get(IntlCalendar::FIELD_MONTH), "\n";
echo 'fromDate_d=', $d->get(IntlCalendar::FIELD_DATE), "\n";

$t = IntlGregorianCalendar::createFromDateTime(2020, 0, 1, 12, 30, 45);
echo 'fromDT_h=', $t->get(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";
echo 'fromDT_i=', $t->get(IntlCalendar::FIELD_MINUTE), "\n";
echo 'fromDT_s=', $t->get(IntlCalendar::FIELD_SECOND), "\n";

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
$p = intlgregcal_create_from_date(2016, 1, 29);
echo 'proc_fromDate_leap=', $p->isLeapYear(2016) ? '1' : '0', "\n";
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
fromDate_y=2020
fromDate_m=0
fromDate_d=1
fromDT_h=12
fromDT_i=30
fromDT_s=45
tz=UTC
tz_type=gregorian
intlgregcal_create_instance=yes
intlgregcal_is_leap_year=yes
intlgregcal_get_gregorian_change=yes
intlgregcal_set_gregorian_change=yes
intlgregcal_create_from_date=yes
intlgregcal_create_from_date_time=yes
proc_leap=1
proc_fromDate_leap=1
