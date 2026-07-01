<?php

// Issue #14632 — array_first()/array_last() withheld on PHP 8.2 reference profile (ext/standard/array.c).
$funcs = ['array_first', 'array_last'];
$exposed = array_filter($funcs, static fn (string $fn): bool => function_exists($fn));
if ([] !== $exposed) {
    echo 'fail: exposed '.implode(',', $exposed)."\n";
    exit(1);
}
echo "ok_no_funcs\n";
