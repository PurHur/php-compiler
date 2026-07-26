<?php
// Repro #23263 — get_debug_type/count/is_* Zend stub $value named params
$checks = [];

$namesOf = static function (string $fn): array {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    return $names;
};

$checks[] = ['value'] === $namesOf('get_debug_type');
$checks[] = 'int' === get_debug_type(value: 1);

$checks[] = ['value', 'mode'] === $namesOf('count');
$checks[] = 1 === count(value: [1]);

$checks[] = ['value', 'mode'] === $namesOf('sizeof');

$checks[] = ['value'] === $namesOf('is_string');
$checks[] = true === is_string(value: 'a');

$checks[] = ['value'] === $namesOf('is_array');
$checks[] = true === is_array(value: [1]);

foreach (['is_countable', 'is_iterable', 'is_integer', 'is_long', 'is_double'] as $fn) {
    $checks[] = ['value'] === $namesOf($fn);
}

$varRejected = false;
try {
    count(var: [1]);
} catch (Error $e) {
    $varRejected = str_contains($e->getMessage(), 'Unknown named parameter $var');
}
$checks[] = $varRejected;

$checks[] = ['num'] === $namesOf('is_finite');
$checks[] = true === is_finite(num: 1.0);

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
