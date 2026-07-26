<?php
// Repro #23205 — hash_equals Reflection + Zend stub named params
$checks = [];

$rf = new ReflectionFunction('hash_equals');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
$checks[] = ['known_string', 'user_string'] === $names;
$checks[] = 2 === $rf->getNumberOfParameters();

$checks[] = true === hash_equals(known_string: 'aa', user_string: 'aa');
$checks[] = false === hash_equals(known_string: 'aa', user_string: 'bb');

$ok = true;
foreach ($checks as $c) {
    if (!$c) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
