<?php
// Repro #25500 — array_change_key_case Zend stub $array named param
$checks = [];

$rf = new ReflectionFunction('array_change_key_case');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$checks[] = ['array', 'case'] === $names;

$a = ['Foo' => 1, 'Bar' => 2];
$checks[] = ['FOO' => 1, 'BAR' => 2] === array_change_key_case(array: $a, case: CASE_UPPER);
$checks[] = ['foo' => 1, 'bar' => 2] === array_change_key_case(array: $a);
$checks[] = ['FOO' => 1, 'BAR' => 2] === array_change_key_case($a, CASE_UPPER);

$inputRejected = false;
try {
    array_change_key_case(input: $a, case: CASE_UPPER);
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
