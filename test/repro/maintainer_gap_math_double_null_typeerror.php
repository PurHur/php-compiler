<?php
declare(strict_types=0);

// Real Zend float builtins only — fadd/fsub/fmul/nextafter are phantoms (#28565).
$probes = [
    ['fdiv', null, 2.0],
    ['fmod', null, 2.0],
    // fpow: Zend 8.4 DEP+coerce null→0.0 (#24177), not TypeError — omitted
    ['hypot', null, 1.0],
    ['atan2', null, 1.0],
];

foreach ($probes as [$fn, $a, $b]) {
    try {
        $fn($a, $b);
        echo "bad:{$fn}:no_throw\n";
    } catch (TypeError $e) {
        echo "ok:{$fn}:TypeError\n";
    }
}
