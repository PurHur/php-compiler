<?php

// Issue #14622 — array_find()/array_find_key() withheld on PHP 8.2 reference profile (re-#14505).
$funcs = ['array_find', 'array_find_key'];
$exposed = array_filter($funcs, static fn (string $fn): bool => function_exists($fn));
if ([] !== $exposed) {
    echo 'fail: exposed '.implode(',', $exposed)."\n";
    exit(1);
}
echo "ok_no_funcs\n";
