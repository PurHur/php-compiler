<?php

declare(strict_types=1);

/**
 * Issue #23875 — array_find family has no $strict; Zend expects exactly 2 args.
 * php-src: ext/standard/basic_functions.stub.php / array.c
 */

foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['array', 'callback'] !== $names) {
        fwrite(STDERR, "fail: {$fn} params=[".implode(',', $names)."]\n");
        exit(1);
    }
}

$haystack = [1, 2, 3];
foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    try {
        $fn($haystack, fn ($v) => $v === 2, true);
        fwrite(STDERR, "fail: {$fn} accepted 3 args\n");
        exit(1);
    } catch (ArgumentCountError $e) {
        if (!str_contains($e->getMessage(), 'expects exactly 2 arguments, 3 given')) {
            fwrite(STDERR, "fail: {$fn} message: {$e->getMessage()}\n");
            exit(1);
        }
    }
}

if (2 !== array_find($haystack, fn ($v) => $v === 2)) {
    fwrite(STDERR, "fail: positional array_find\n");
    exit(1);
}
if (2 !== array_find(array: $haystack, callback: fn ($v) => $v === 2)) {
    fwrite(STDERR, "fail: named array_find\n");
    exit(1);
}

echo "ok\n";
