<?php
// Repro #23490 — array_fill_keys Zend stub named parameters (keys/value)
$ok = true;
$names = [];
foreach ((new ReflectionFunction('array_fill_keys'))->getParameters() as $p) {
    $names[] = $p->getName();
}
if (['keys', 'value'] !== $names) {
    $ok = false;
}
$filled = array_fill_keys(keys: ['a', 'b'], value: 1);
if (['a' => 1, 'b' => 1] !== $filled) {
    $ok = false;
}
$mixed = array_fill_keys(['x'], value: 2);
if (['x' => 2] !== $mixed) {
    $ok = false;
}
try {
    array_fill_keys(keys: ['a'], val: 1);
    $ok = false;
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'Unknown named parameter $val')) {
        $ok = false;
    }
}
echo $ok ? "ok\n" : "fail\n";
