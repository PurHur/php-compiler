<?php
/**
 * #29998 — NumberFormatter::CASH_CURRENCY / CURRENCY_STANDARD on PROFILE=8.5.
 */
declare(strict_types=1);

if (!class_exists('NumberFormatter')) {
    echo "skip: NumberFormatter withheld (extension_loaded('intl') false)\n";
    exit(0);
}

$need = [
    'CASH_CURRENCY' => 13,
    'CURRENCY_STANDARD' => 16,
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
