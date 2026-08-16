<?php

// Issue #23445 — iterator_apply Reflection/named args are iterator/callback/args (not it/function/params).
$rf = new ReflectionFunction('iterator_apply');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$it = new ArrayIterator([1]);
try {
    var_export(iterator_apply(iterator: $it, callback: fn () => true));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    iterator_apply(it: $it, function: fn () => true);
    echo "unexpected it ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
