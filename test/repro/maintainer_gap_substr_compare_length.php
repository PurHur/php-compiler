<?php

declare(strict_types=1);

/**
 * Issue #10598: substr_compare() explicit $length longer than needle.
 *
 * php-src: ext/standard/string.c — php_substr_compare
 */
$longer = substr_compare('hello', 'ell', 1, 10);
$exact = substr_compare('hello', 'ell', 1, 3);

echo "longer={$longer}\n";
echo "exact={$exact}\n";

if (1 !== $longer) {
    fwrite(STDERR, "expected longer=1, got {$longer}\n");
    exit(1);
}
if (0 !== $exact) {
    fwrite(STDERR, "expected exact=0, got {$exact}\n");
    exit(1);
}

exit(0);
