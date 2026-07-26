<?php
// Repro for #23422 — class_alias Reflection / named args vs Zend stubs
$rf = new ReflectionFunction('class_alias');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
class Orig {}
try {
    class_alias(class: 'Orig', alias: 'Alias1');
    echo class_exists('Alias1') ? 'Y' : 'N', "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
