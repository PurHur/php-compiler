<?php
declare(strict_types=1);

/**
 * Repro #25358 — RecursiveCachingIterator CALL_TOSTRING warns on array current.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo 'WARN:'.$m."\n";

    return true;
});

$it = new RecursiveArrayIterator([1, [2, 3]]);
$c = new RecursiveCachingIterator($it);
echo 'count='.iterator_count(new RecursiveIteratorIterator($c))."\n";
