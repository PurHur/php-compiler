<?php
// Repro #23274 — array_keys/values/unique/flip Zend stub $array named params
$checks = [];

$namesOf = static function (string $fn): array {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }

    return $names;
};

$checks[] = ['array', 'filter_value', 'strict'] === $namesOf('array_keys');
$checks[] = ['array'] === $namesOf('array_values');
$checks[] = ['array', 'flags'] === $namesOf('array_unique');
$checks[] = ['array'] === $namesOf('array_flip');

$a = ['a' => 1, 'b' => 2];
$checks[] = ['a', 'b'] === array_keys(array: $a);
$checks[] = ['b'] === array_keys(array: $a, filter_value: 2, strict: true);
$checks[] = [1, 2] === array_values(array: $a);
$u = array_unique(array: [1, 1, 2], flags: SORT_NUMERIC);
$checks[] = isset($u[0], $u[2]) && 1 === $u[0] && 2 === $u[2] && 2 === count($u);
$f = array_flip(array: ['a' => 1]);
$checks[] = isset($f[1]) && 'a' === $f[1] && 1 === count($f);
$checks[] = ['a', 'b'] === array_keys($a);

$inputRejected = false;
try {
    array_keys(input: $a);
} catch (Error $e) {
    $inputRejected = str_contains($e->getMessage(), 'Unknown named parameter $input');
}
$checks[] = $inputRejected;

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
