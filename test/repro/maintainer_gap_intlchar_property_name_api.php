<?php

declare(strict_types=1);

/**
 * Repro for #20787 — IntlChar property/name catalog APIs.
 *
 * php-src: ext/intl/uchar/uchar.stub.php, ICU u_getProperty* / u_charFromName
 */
$methods = [
    'getPropertyValueEnum',
    'getPropertyName',
    'getPropertyEnum',
    'getPropertyValueName',
    'getIntPropertyValue',
    'getIntPropertyMinValue',
    'getIntPropertyMaxValue',
    'charFromName',
    'getUnicodeVersion',
    'getNumericValue',
    'charDigitValue',
];
foreach ($methods as $m) {
    echo $m, '=', method_exists(IntlChar::class, $m) ? '1' : '0', "\n";
}
echo 'fromName=', IntlChar::charFromName('LATIN CAPITAL LETTER A'), "\n";
echo 'propName=', IntlChar::getPropertyName(IntlChar::PROPERTY_ALPHABETIC), "\n";
echo 'propEnum=', IntlChar::getPropertyEnum('Alphabetic'), "\n";
echo 'valEnum=', IntlChar::getPropertyValueEnum(IntlChar::PROPERTY_ALPHABETIC, 'Yes'), "\n";
echo 'intVal=', IntlChar::getIntPropertyValue(0x41, IntlChar::PROPERTY_ALPHABETIC), "\n";
echo 'min=', IntlChar::getIntPropertyMinValue(IntlChar::PROPERTY_ALPHABETIC), "\n";
echo 'max=', IntlChar::getIntPropertyMaxValue(IntlChar::PROPERTY_ALPHABETIC), "\n";
$ver = IntlChar::getUnicodeVersion();
echo 'ver0=', $ver[0], "\n";
echo 'numeric=', IntlChar::getNumericValue(0x35), "\n";
echo 'digit=', IntlChar::charDigitValue(0x35), "\n";
