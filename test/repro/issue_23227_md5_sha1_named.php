<?php
// Repro #23227 — md5/sha1 Zend stub named parameters (string/binary)
$ok = true;
foreach (['md5', 'sha1'] as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['string', 'binary'] !== $names) {
        $ok = false;
    }
    $digest = $fn(string: 'x');
    $expected = $fn('x');
    if ($digest !== $expected) {
        $ok = false;
    }
    $raw = $fn(string: 'x', binary: true);
    if ($raw !== $fn('x', true)) {
        $ok = false;
    }
    try {
        $fn(str: 'x');
        $ok = false;
    } catch (Error $e) {
        if (!str_contains($e->getMessage(), 'Unknown named parameter $str')) {
            $ok = false;
        }
    }
    try {
        $fn(string: 'x', raw_output: true);
        $ok = false;
    } catch (Error $e) {
        if (!str_contains($e->getMessage(), 'Unknown named parameter $raw_output')) {
            $ok = false;
        }
    }
}
echo $ok ? "ok\n" : "fail\n";
