<?php

declare(strict_types=1);

/**
 * Issue #16513 — preg_match()/preg_match_all() negative $offset (ext/pcre/php_pcre.c).
 */

preg_match('/(\w+)/', 'abc', $m, PREG_OFFSET_CAPTURE, -1);
if ([] === $m || 'c' !== $m[0][0] || 2 !== $m[0][1]) {
    fwrite(STDERR, 'preg_match negative offset: ');
    var_export($m);
    fwrite(STDERR, "\n");
    exit(1);
}

preg_match_all('/a/', 'banana', $m2, PREG_OFFSET_CAPTURE, -1);
if (
    !isset($m2[0][0][0], $m2[0][0][1])
    || 'a' !== $m2[0][0][0]
    || 5 !== $m2[0][0][1]
) {
    fwrite(STDERR, 'preg_match_all negative offset: ');
    var_export($m2);
    fwrite(STDERR, "\n");
    exit(1);
}

echo "ok\n";
