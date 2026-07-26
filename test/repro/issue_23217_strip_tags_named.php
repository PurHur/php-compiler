<?php
// Repro #23217 — strip_tags Zend stub named parameters (string/allowed_tags)
$names = [];
foreach ((new ReflectionFunction('strip_tags'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$kept = strip_tags(string: '<b>x</b>', allowed_tags: '<b>');
$stripped = strip_tags(string: '<b>x</b>');
$ok = ['string', 'allowed_tags'] === $names
    && $kept === '<b>x</b>'
    && $stripped === 'x';
try {
    strip_tags(str: '<b>x</b>', allowable_tags: '<b>');
    $legacyRejected = false;
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'Unknown named parameter $str');
}
echo ($ok && $legacyRejected) ? "ok\n" : "fail\n";
