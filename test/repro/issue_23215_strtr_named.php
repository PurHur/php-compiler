<?php
// Repro #23215 — strtr Zend stub named parameters (string/from/to)
$names = [];
foreach ((new ReflectionFunction('strtr'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$three = strtr(string: 'abc', from: 'a', to: 'x');
$pairs = strtr(string: 'baab', from: ['a' => 'o']);
$ok = ['string', 'from', 'to'] === $names
    && $three === 'xbc'
    && $pairs === 'boob';
try {
    strtr(str: 'abc', from: 'a', to: 'x');
    $legacyRejected = false;
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $str');
}
echo ($ok && $legacyRejected) ? "ok\n" : "fail\n";
