<?php

declare(strict_types=1);

/**
 * #23949 — PROFILE=8.4 must not expose parse_str $separator (php-src arity 2).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_23949_parse_str_separator_phantom.php
 */

$r = new ReflectionFunction('parse_str');
if (2 !== $r->getNumberOfParameters()) {
    echo 'FAIL argc=', $r->getNumberOfParameters(), "\n";
    exit(1);
}
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
if (['string', 'result'] !== $names) {
    echo 'FAIL names=', implode(',', $names), "\n";
    exit(1);
}

$out = [];
$caught = false;
try {
    parse_str('a=1;b=2', $out, ';');
} catch (ArgumentCountError $e) {
    $caught = true;
    if ('parse_str() expects exactly 2 arguments, 3 given' !== $e->getMessage()) {
        echo 'FAIL message=', $e->getMessage(), "\n";
        exit(1);
    }
}
if (!$caught) {
    echo "FAIL: 3-arg parse_str should ArgumentCountError\n";
    exit(1);
}

parse_str('a=1;b=2', $out);
if ($out !== ['a' => '1;b=2']) {
    echo 'FAIL parse=', var_export($out, true), "\n";
    exit(1);
}

echo "ok\n";
