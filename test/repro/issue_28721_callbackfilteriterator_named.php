<?php

// Issue #28721 — CallbackFilterIterator::__construct Reflection/named args are iterator/callback (not it/func).
$r = new ReflectionMethod(CallbackFilterIterator::class, '__construct');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";

$it = new ArrayIterator([1, 2, 3]);
$fn = static fn ($v) => true;
try {
    new CallbackFilterIterator(iterator: $it, callback: $fn);
    echo "iterator/callback: OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new CallbackFilterIterator(it: $it, func: $fn);
    echo "it/func: unexpected OK\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
