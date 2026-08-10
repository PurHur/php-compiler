<?php
/**
 * #30000 — Collator/Normalizer/IntlChar(/IntlListFormatter) const exact casing.
 */
declare(strict_types=1);

if (!class_exists('Collator') || !class_exists('Normalizer') || !class_exists('IntlChar')) {
    echo "skip: intl OOP withheld (extension_loaded('intl') false)\n";
    exit(0);
}

$checks = [
    'Collator::DEFAULT_STRENGTH',
    'Normalizer::FORM_D',
    'IntlChar::PROPERTY_ALPHABETIC',
];
if (class_exists('IntlListFormatter')) {
    $ref = new ReflectionClass('IntlListFormatter');
    $consts = $ref->getConstants();
    if ($consts) {
        $checks[] = 'IntlListFormatter::'.array_key_first($consts);
    }
}
foreach ($checks as $f) {
    [$cls, $c] = explode('::', $f, 2);
    $ref = new ReflectionClass($cls);
    echo $f,
        ' defined=', defined($f) ? 'y' : 'n',
        ' has=', $ref->hasConstant($c) ? 'y' : 'n',
        ' wrong=', $ref->hasConstant(strtolower($c)) ? 'y' : 'n',
        "\n";
}
