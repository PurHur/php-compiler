<?php
// Repro #23657 — mb_strtolower / mb_strtoupper Zend stub named params
$lowerNames = [];
foreach ((new ReflectionFunction('mb_strtolower'))->getParameters() as $p) {
    $lowerNames[] = $p->getName();
}
$upperNames = [];
foreach ((new ReflectionFunction('mb_strtoupper'))->getParameters() as $p) {
    $upperNames[] = $p->getName();
}
$lower = mb_strtolower(string: 'AbC');
$upper = mb_strtoupper(string: 'AbC');
$positionalLower = mb_strtolower('AbC');
$positionalUpper = mb_strtoupper('AbC');
$legacyRejected = false;
try {
    mb_strtolower(str: 'AbC');
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'str');
}
$ok = ['string', 'encoding'] === $lowerNames
    && ['string', 'encoding'] === $upperNames
    && 'abc' === $lower
    && $lower === $positionalLower
    && 'ABC' === $upper
    && $upper === $positionalUpper
    && $legacyRejected;
echo $ok ? "ok\n" : "fail\n";
