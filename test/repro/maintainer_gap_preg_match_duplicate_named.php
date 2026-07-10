<?php

declare(strict_types=1);

// Issue #17584 — duplicate named subpatterns without PCRE2_DUPNAMES must not match.
$matches = [];
$result = @preg_match('/(?<x>a)(?<x>b)/', 'ab', $matches);
if (false !== $result) {
    fwrite(STDERR, "fail: preg_match must return false for duplicate named subpatterns\n");
    exit(1);
}
if (1 !== preg_last_error()) {
    fwrite(STDERR, 'fail: preg_last_error expected 1, got '.preg_last_error()."\n");
    exit(1);
}
if ([] !== $matches) {
    fwrite(STDERR, "fail: matches must be empty on compile failure\n");
    exit(1);
}

$replaced = preg_replace('/(?<x>a)(?<x>b)/', 'X', 'ab');
if (null !== $replaced) {
    fwrite(STDERR, "fail: preg_replace must return null for duplicate named subpatterns\n");
    exit(1);
}

echo "ok\n";
