<?php
// Repro #24578 — bcdivmod() Reflection + named args (ext/bcmath/bcmath.stub.php)

$names = [];
foreach ((new ReflectionFunction('bcdivmod'))->getParameters() as $p) {
    $names[] = $p->getName().($p->isOptional() ? '?' : '');
}
$rf = new ReflectionFunction('bcdivmod');
$checks = [
    3 === $rf->getNumberOfParameters(),
    ['num1', 'num2', 'scale?'] === $names,
    2 === $rf->getNumberOfRequiredParameters(),
];

try {
    $pair = bcdivmod(num1: '10', num2: '3');
    $checks[] = ['3', '1'] === $pair;
} catch (Throwable $e) {
    $checks[] = false;
}

try {
    $pair = bcdivmod(num1: '10', num2: '3', scale: 0);
    $checks[] = ['3', '1'] === $pair;
} catch (Throwable $e) {
    $checks[] = false;
}

try {
    bcdivmod(left: '10', num2: '3');
    $checks[] = false;
} catch (Error $e) {
    $checks[] = str_contains($e->getMessage(), 'Unknown named parameter $left');
}

$checks[] = ['3', '1'] === bcdivmod('10', '3');

echo (!in_array(false, $checks, true)) ? "ok\n" : "fail\n";
