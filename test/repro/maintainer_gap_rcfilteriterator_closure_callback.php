<?php

declare(strict_types=1);

/**
 * RecursiveCallbackFilterIterator accepts inline Closure callbacks (#13180).
 */

function rcf_accept(mixed $cur, mixed $key, mixed $filter): bool
{
    return true;
}

$inner = new RecursiveArrayIterator(['a' => 1, 'b' => 2]);

$it = new RecursiveCallbackFilterIterator($inner, function ($cur, $key, $filter): bool {
    return true;
});
$it->rewind();
if (!$it->valid()) {
    fwrite(STDERR, "fail: inline closure rewind\n");
    exit(1);
}

$it2 = new RecursiveCallbackFilterIterator($inner, fn ($cur, $key, $filter): bool => true);
$it2->rewind();
if (!$it2->valid()) {
    fwrite(STDERR, "fail: arrow closure rewind\n");
    exit(1);
}

$it3 = new RecursiveCallbackFilterIterator($inner, 'rcf_accept');
$it3->rewind();
if (!$it3->valid()) {
    fwrite(STDERR, "fail: string callback rewind\n");
    exit(1);
}

echo "ok\n";
