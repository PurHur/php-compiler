<?php
/**
 * Issue #24461 — ReflectionParameter::isVariadic() on internal functions must
 * return Zend bools (not ReflectionException "Function …() does not exist").
 *
 * php-src: ext/reflection/php_reflection.c zim_reflection_parameter_isVariadic
 *
 *   php bin/vm.php test/repro/maintainer_gap_reflection_is_variadic.php
 *   php test/repro/maintainer_gap_reflection_is_variadic.php
 */
$cases = [
    'strlen' => ['string' => false],
    'array_map' => ['callback' => false, 'array' => false, 'arrays' => true],
    'call_user_func' => ['callback' => false, 'args' => true],
    'sprintf' => ['format' => false, 'values' => true],
    'pack' => ['format' => false, 'values' => true],
];

foreach ($cases as $fn => $expected) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' rfIsV=', $rf->isVariadic() ? 'T' : 'F', "\n";
    foreach ($rf->getParameters() as $p) {
        $name = $p->getName();
        $isV = $p->isVariadic();
        $want = $expected[$name] ?? null;
        echo '  ', $name, ' isV=', $isV ? 'T' : 'F';
        if (null !== $want && $want !== $isV) {
            echo ' MISMATCH want=', $want ? 'T' : 'F';
        }
        echo "\n";
    }
}
