<?php
// Repro #23351 — mb_strimwidth Zend stub named parameters (trim_marker)
$names = [];
foreach ((new ReflectionFunction('mb_strimwidth'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$trimmed = mb_strimwidth(string: 'hello', start: 0, width: 3, trim_marker: '..');
$ok = ['string', 'start', 'width', 'trim_marker', 'encoding'] === $names
    && $trimmed === 'h..';
try {
    mb_strimwidth(string: 'hello', start: 0, width: 3, trimmarker: '..');
    $legacyRejected = false;
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $trimmarker');
}
echo ($ok && $legacyRejected) ? "ok\n" : "fail\n";
