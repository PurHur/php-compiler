<?php
// Repro #23242 — range Zend stub named parameters (start/end/step)
$names = [];
foreach ((new ReflectionFunction('range'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$one = range(start: 1, end: 3);
$two = range(start: 1, end: 5, step: 2);
$ok = ['start', 'end', 'step'] === $names
    && $one === [1, 2, 3]
    && $two === [1, 3, 5];
try {
    range(low: 1, high: 3);
    $legacyRejected = false;
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $low');
}
echo ($ok && $legacyRejected) ? "ok\n" : "fail\n";
