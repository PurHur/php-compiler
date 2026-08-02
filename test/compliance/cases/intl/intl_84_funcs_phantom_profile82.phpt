--TEST--
intlgregcal_create_from_date*/intltz_get_utc + getUTC withheld on default/PROFILE=8.2 (#26745)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF--.
if (!extension_loaded('intl')) {
    echo "skip\n";
    exit(0);
}
foreach ([
    'intlgregcal_create_from_date',
    'intlgregcal_create_from_date_time',
    'intltz_get_utc',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'Y' : 'N', "\n";
}
echo 'createFromDate=', method_exists('IntlGregorianCalendar', 'createFromDate') ? 'Y' : 'N', "\n";
echo 'createFromDateTime=', method_exists('IntlGregorianCalendar', 'createFromDateTime') ? 'Y' : 'N', "\n";
echo 'getUTC=', method_exists('IntlTimeZone', 'getUTC') ? 'Y' : 'N', "\n";
?>
--EXPECT--
intlgregcal_create_from_date=N
intlgregcal_create_from_date_time=N
intltz_get_utc=N
createFromDate=N
createFromDateTime=N
getUTC=N
