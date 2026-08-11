<?php
/**
 * Issue #30139 — ~array/~object/~false TypeError text must match Zend 8.4.
 *
 * Zend: "Cannot perform bitwise not on array|stdClass|false|true"
 */

$cases = [
    'array' => fn() => ~[1],
    'object' => fn() => ~(new stdClass),
    'false' => fn() => ~false,
    'true' => fn() => ~true,
    'null' => fn() => ~null,
];

foreach ($cases as $label => $fn) {
    try {
        $fn();
        echo "$label: BUG no error\n";
    } catch (\TypeError $e) {
        echo "$label: " . $e->getMessage() . "\n";
    }
}
