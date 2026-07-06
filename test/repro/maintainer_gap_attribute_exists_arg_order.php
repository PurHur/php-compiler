<?php

declare(strict_types=1);

/**
 * Repro for #16844 — attribute_exists() Zend operand order (attribute, then object/class).
 */
#[\Deprecated]
class DeprecatedDemo {}

$zendOrder = attribute_exists('Deprecated', DeprecatedDemo::class);
$swapped = attribute_exists(DeprecatedDemo::class, 'Deprecated');

if (!$zendOrder) {
    echo "fail: attribute_exists('Deprecated', class) expected true\n";
    exit(1);
}
if ($swapped) {
    echo "fail: swapped attribute_exists(class, 'Deprecated') expected false\n";
    exit(1);
}

echo "ok\n";
