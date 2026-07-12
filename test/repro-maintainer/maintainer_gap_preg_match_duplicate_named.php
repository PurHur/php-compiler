<?php

declare(strict_types=1);

/** Issue #17584 — duplicate named subpatterns must fail compile (ext/pcre/php_pcre.c). */

$m = [];
$match = @preg_match('/(?<x>a)(?<x>b)/', 'ab', $m);
if (false !== $match) {
    echo "fail: preg_match expected false, got " . var_export($match, true) . "\n";
    exit(1);
}
if (1 !== preg_last_error()) {
    echo "fail: preg_last_error expected 1, got " . preg_last_error() . "\n";
    exit(1);
}

$rep = preg_replace('/(?<x>a)(?<x>b)/', 'X', 'ab');
if (null !== $rep) {
    echo "fail: preg_replace expected null, got " . var_export($rep, true) . "\n";
    exit(1);
}
if (1 !== preg_last_error()) {
    echo "fail: preg_last_error expected 1 after preg_replace, got " . preg_last_error() . "\n";
    exit(1);
}

echo "ok\n";
