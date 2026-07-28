<?php
declare(strict_types=0);

$probes = [
    ['fadd', null, 1.0],
    ['fsub', null, 1.0],
    ['fmul', null, 2.0],
    ['fdiv', null, 2.0],
    ['fmod', null, 2.0],
    // fpow: Zend 8.4 DEP+coerce null→0.0 (#24177), not TypeError — omitted
    ['hypot', null, 1.0],
    ['atan2', null, 1.0],
    ['nextafter', 1.0, null],
];

foreach ($probes as [$fn, $a, $b]) {
    try {
        $fn($a, $b);
        echo "bad:{$fn}:no_throw\n";
    } catch (TypeError $e) {
        echo "ok:{$fn}:TypeError\n";
    }
}
