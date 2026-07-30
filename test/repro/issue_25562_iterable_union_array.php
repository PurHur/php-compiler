<?php
declare(strict_types=1);

// Issue #25562 — iterable|string must accept arrays (php-src-strict).
function f(iterable|string $x): string {
    if (is_string($x)) {
        return 's';
    }
    if (is_array($x)) {
        return 'a' . count($x);
    }

    return 'i';
}

echo f('x'), "\n";
echo f([1, 2]), "\n";
echo f((function () { yield 1; })()), "\n";
echo f(new ArrayIterator([1])), "\n";

try {
    f(new stdClass);
    fwrite(STDERR, "FAIL: stdClass accepted\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'Traversable|array|string')) {
        fwrite(STDERR, "FAIL: unexpected message: ".$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
