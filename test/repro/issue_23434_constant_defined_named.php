<?php
// Repro for #23434 — constant/defined Reflection / named args vs Zend stubs
$rf = new ReflectionFunction('constant');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$rf = new ReflectionFunction('defined');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
define('K', 1);
try {
    echo constant(name: 'K'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo defined(constant_name: 'K') ? 'Y' : 'N', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
