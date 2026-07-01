<?php

// Issue #14621 — array_any()/array_all() withheld on PHP 8.2 reference profile (ext/standard/array.c).
$funcs = ['array_any', 'array_all'];
$exposed = array_filter($funcs, static fn (string $fn): bool => function_exists($fn));
if ([] !== $exposed) {
    echo 'fail: exposed '.implode(',', $exposed)."\n";
    exit(1);
}
echo "ok_no_funcs\n";
