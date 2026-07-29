<?php

declare(strict_types=1);

// Issue #24577 — str_increment/str_decrement named string: must match positional.

foreach (['str_decrement', 'str_increment'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $n = [];
    foreach ($rf->getParameters() as $p) {
        $n[] = $p->getName();
    }
    echo $fn, ' arity=', $rf->getNumberOfParameters(), ' [', implode(',', $n), "]\n";
}

echo str_decrement(string: 'b'), "\n";
echo str_increment(string: 'a'), "\n";
