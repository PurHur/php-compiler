--TEST--
IntlGregorianCalendar::createFromDate/createFromDateTime on PROFILE=8.3 (#20906, #26745)
--ENV--
PHP_COMPILER_PROFILE=8.3
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlGregorianCalendar withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'createFromDate=', method_exists('IntlGregorianCalendar', 'createFromDate') ? 'yes' : 'no', "\n";
echo 'createFromDateTime=', method_exists('IntlGregorianCalendar', 'createFromDateTime') ? 'yes' : 'no', "\n";
// Procedural aliases never existed in php-src php_intl.stub.php (#26745)
echo 'proc_date=', function_exists('intlgregcal_create_from_date') ? 'yes' : 'no', "\n";
echo 'proc_dt=', function_exists('intlgregcal_create_from_date_time') ? 'yes' : 'no', "\n";

$d = IntlGregorianCalendar::createFromDate(2020, 0, 1);
echo 'fromDate_y=', $d->get(IntlCalendar::FIELD_YEAR), "\n";
echo 'fromDate_m=', $d->get(IntlCalendar::FIELD_MONTH), "\n";
echo 'fromDate_d=', $d->get(IntlCalendar::FIELD_DATE), "\n";

$t = IntlGregorianCalendar::createFromDateTime(2020, 0, 1, 12, 30, 45);
echo 'fromDT_h=', $t->get(IntlCalendar::FIELD_HOUR_OF_DAY), "\n";
echo 'fromDT_i=', $t->get(IntlCalendar::FIELD_MINUTE), "\n";
echo 'fromDT_s=', $t->get(IntlCalendar::FIELD_SECOND), "\n";
?>
--EXPECT--
createFromDate=yes
createFromDateTime=yes
proc_date=no
proc_dt=no
fromDate_y=2020
fromDate_m=0
fromDate_d=1
fromDT_h=12
fromDT_i=30
fromDT_s=45
