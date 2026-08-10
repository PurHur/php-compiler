<?php
/**
 * #28132 — NumberFormatter PHP 8.5 style constants (CURRENCY_ISO/PLURAL + DECIMAL_COMPACT_*).
 *
 * php-src: ext/intl/formatter/formatter.stub.php — ICU UNUM_* values 10/11/14/15.
 */
declare(strict_types=1);

if (!class_exists('NumberFormatter')) {
    echo "skip: NumberFormatter withheld (extension_loaded('intl') false)\n";
    exit(0);
}

$need = [
    'CURRENCY_ISO' => 10,
    'CURRENCY_PLURAL' => 11,
    'DECIMAL_COMPACT_SHORT' => 14,
    'DECIMAL_COMPACT_LONG' => 15,
];

$ref = new ReflectionClass(NumberFormatter::class);
foreach ($need as $c => $expect) {
    $full = 'NumberFormatter::'.$c;
    $viaDefined = defined($full) ? (int) constant($full) : 'MISSING';
    $viaHas = $ref->hasConstant($c) ? (int) $ref->getConstant($c) : 'MISSING';
    echo $c, ' defined=', $viaDefined, ' has=', $viaHas, ' expect=', $expect, "\n";
}

foreach (array_keys($need) as $c) {
    $style = constant('NumberFormatter::'.$c);
    try {
        $fmt = new NumberFormatter('en_US', $style);
        echo $c, ' ctor=', $fmt instanceof NumberFormatter ? 'ok' : 'bad', "\n";
    } catch (Throwable $e) {
        echo $c, ' ctor=', $e::class, ':', $e->getMessage(), "\n";
    }
}
