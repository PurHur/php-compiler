<?php
/**
 * Repro #26745 — intlgregcal_create_from_date* / intltz_get_utc phantoms on default profile.
 *
 * Zend 8.2 (and php-src generally for the procedural forms / getUTC) reports function_exists false.
 * OO createFromDate* is PHP 8.3+ only.
 */
declare(strict_types=1);

foreach ([
    'intlgregcal_create_from_date',
    'intlgregcal_create_from_date_time',
    'intltz_get_utc',
] as $fn) {
    echo $fn, '=', function_exists($fn) ? '1' : '0', "\n";
}
echo 'createFromDate=', method_exists('IntlGregorianCalendar', 'createFromDate') ? '1' : '0', "\n";
echo 'createFromDateTime=', method_exists('IntlGregorianCalendar', 'createFromDateTime') ? '1' : '0', "\n";
echo 'getUTC=', method_exists('IntlTimeZone', 'getUTC') ? '1' : '0', "\n";
echo 'extension_loaded_intl=', extension_loaded('intl') ? '1' : '0', "\n";
