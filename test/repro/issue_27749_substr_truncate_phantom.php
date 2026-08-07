<?php

declare(strict_types=1);

/**
 * #27749 — PROFILE≥8.4 substr() arity matches php-src (no $truncate).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_27749_substr_truncate_phantom.php
 */

$r = new ReflectionFunction('substr');
if (3 !== $r->getNumberOfParameters()) {
    echo 'FAIL argc=', $r->getNumberOfParameters(), "\n";
    exit(1);
}
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
if (['string', 'offset', 'length'] !== $names) {
    echo 'FAIL names=', implode(',', $names), "\n";
    exit(1);
}

$caught = false;
try {
    substr('abcdef', 0, 3, true);
} catch (ArgumentCountError $e) {
    $caught = true;
    if ('substr() expects at most 3 arguments, 4 given' !== $e->getMessage()) {
        echo 'FAIL message=', $e->getMessage(), "\n";
        exit(1);
    }
}
if (!$caught) {
    echo "FAIL: 4-arg substr should ArgumentCountError\n";
    exit(1);
}

$namedCaught = false;
try {
    substr(string: 'abcdef', offset: 0, length: 3, truncate: true);
} catch (Error $e) {
    $namedCaught = true;
    if (!str_contains($e->getMessage(), 'truncate')) {
        echo 'FAIL named=', $e->getMessage(), "\n";
        exit(1);
    }
}
if (!$namedCaught) {
    echo "FAIL: named truncate should Error\n";
    exit(1);
}

if ('abc' !== substr('abcdef', 0, 3)) {
    echo "FAIL 3-arg substr\n";
    exit(1);
}

echo "ok\n";
