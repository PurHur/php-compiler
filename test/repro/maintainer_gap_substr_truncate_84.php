<?php

declare(strict_types=1);

/**
 * #27749 — PROFILE=8.4 must not expose substr $truncate (php-src arity 3).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_substr_truncate_84.php
 */

if ('8.4' !== getenv('PHP_COMPILER_PROFILE') && '8.5' !== getenv('PHP_COMPILER_PROFILE')) {
    fwrite(STDERR, "skip: requires PHP_COMPILER_PROFILE=8.4 or 8.5\n");
    exit(0);
}

$r = new ReflectionFunction('substr');
if (3 !== $r->getNumberOfParameters()) {
    fwrite(STDERR, 'FAIL argc='.$r->getNumberOfParameters()."\n");
    exit(1);
}

try {
    substr('hello world', 0, 50, true);
    fwrite(STDERR, "FAIL: 4-arg substr should ArgumentCountError\n");
    exit(1);
} catch (ArgumentCountError $e) {
    if ('substr() expects at most 3 arguments, 4 given' !== $e->getMessage()) {
        fwrite(STDERR, 'FAIL message='.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    substr(string: 'hello world', offset: 0, length: 50, truncate: true);
    fwrite(STDERR, "FAIL: named truncate should Error\n");
    exit(1);
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'truncate')) {
        fwrite(STDERR, 'FAIL named='.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    mb_substr('hello world', 0, 50, 'UTF-8', true);
    fwrite(STDERR, "mb_substr_err: expected ArgumentCountError for 5th arg\n");
    exit(1);
} catch (ArgumentCountError|Error $e) {
    // php-src has no mb_substr $truncate — arity 4 only (#23603).
}

echo "ok\n";
