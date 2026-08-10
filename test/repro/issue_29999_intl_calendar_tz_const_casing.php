<?php
/**
 * #29999 — IntlCalendar / IntlTimeZone class const exact casing for defined()/hasConstant.
 */
declare(strict_types=1);

if (!class_exists('IntlCalendar') || !class_exists('IntlTimeZone')) {
    echo "skip: intl OOP withheld (extension_loaded('intl') false)\n";
    exit(0);
}

$checks = [
    'IntlCalendar::FIELD_ERA',
    'IntlGregorianCalendar::FIELD_ERA',
    'IntlTimeZone::DISPLAY_SHORT',
];
foreach ($checks as $f) {
    [$cls, $c] = explode('::', $f, 2);
    $ref = new ReflectionClass($cls);
    echo $f,
        ' defined=', defined($f) ? 'y' : 'n',
        ' has=', $ref->hasConstant($c) ? 'y' : 'n',
        ' wrong=', $ref->hasConstant(strtolower($c)) ? 'y' : 'n',
        "\n";
}
