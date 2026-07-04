<?php

declare(strict_types=1);

/**
 * Issue #9619 — SPL iterator TypeError paths must not fatal on TYPE_BOOL typo.
 */

$it = new ArrayIterator([1, 2, 3]);
echo 'count=', iterator_count($it), "\n";

foreach ($it as $k => $v) {
    echo "$k=$v\n";
}

$it->rewind();
echo 'current=', $it->current(), "\n";

try {
    new ArrayIterator('not-an-array');
    echo "FAIL: expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    echo 'typeerror=', str_contains($e->getMessage(), 'array') ? 'ok' : $e->getMessage(), "\n";
}

$empty = new ArrayIterator();
echo 'empty_count=', iterator_count($empty), "\n";
