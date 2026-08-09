<?php
declare(strict_types=0);

/**
 * #29319 — real Zend float builtins soft-null under PROFILE=8.4 (DEP+coerce).
 * fadd/fsub/fmul/nextafter remain phantoms / strict (#28565, #19182).
 */
error_reporting(E_ALL);
$probes = [
    ['fdiv', null, 2.0],
    ['fmod', null, 2.0],
    ['hypot', null, 1.0],
    ['atan2', null, 1.0],
];

foreach ($probes as [$fn, $a, $b]) {
    try {
        $result = $fn($a, $b);
        $out = is_nan($result) ? 'NAN' : var_export($result, true);
        echo "ok:{$fn}:coerce={$out}\n";
    } catch (TypeError $e) {
        echo "bad:{$fn}:TypeError\n";
    }
}
