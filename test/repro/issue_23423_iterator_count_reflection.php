<?php

// Issue #23423 — iterator_count Reflection/named arg is $iterator (not $it).
$rf = new ReflectionFunction('iterator_count');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$it = new ArrayIterator([1, 2, 3]);
try {
    echo iterator_count(iterator: $it), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
