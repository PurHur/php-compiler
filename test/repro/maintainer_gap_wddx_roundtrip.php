<?php

declare(strict_types=1);

/**
 * Issue #6327 repro — wddx_serialize_value()/wddx_deserialize() round-trip.
 */
if (!function_exists('wddx_serialize_value')) {
    fwrite(STDERR, "skip: wddx withheld on reference profile\n");
    exit(0);
}

$x = ['a' => 1, 'b' => 'two', 'c' => [10, 20]];
$packet = wddx_serialize_value($x);
$round = wddx_deserialize($packet);
if (!\is_array($round)) {
    fwrite(STDERR, "fail: expected array from deserialize\n");
    exit(1);
}
if ($round !== $x) {
    fwrite(STDERR, "fail: round-trip mismatch\n");
    var_export($round);
    exit(1);
}

$scalar = wddx_deserialize(wddx_serialize_value(42));
if (42 !== $scalar) {
    fwrite(STDERR, "fail: scalar round-trip\n");
    exit(1);
}

echo "ok\n";
