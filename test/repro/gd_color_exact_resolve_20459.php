<?php
declare(strict_types=1);

/**
 * Repro for #20459 — transparent/exact/resolve (+alpha) registration.
 */
foreach ([
    'imagecolortransparent',
    'imagecolorexact',
    'imagecolorresolve',
    'imagecolorexactalpha',
    'imagecolorresolvealpha',
    'imagecolorclosestalpha',
] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
