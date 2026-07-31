<?php
// Repro #23262 — array_is_list / array_key_first / array_key_last Zend stub $array named params
$checks = [];

$namesOf = static function (string $fn): array {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }

    return $names;
};

foreach (['array_is_list', 'array_key_first', 'array_key_last'] as $fn) {
    $checks[] = ['array'] === $namesOf($fn);
}

$checks[] = true === array_is_list(array: [0, 1]);
$checks[] = true === array_is_list([0, 1]);
$checks[] = 'a' === array_key_first(array: ['a' => 1, 'b' => 2]);
$checks[] = 'b' === array_key_last(array: ['a' => 1, 'b' => 2]);

$inputRejected = false;
try {
    array_is_list(input: [0, 1]);
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
