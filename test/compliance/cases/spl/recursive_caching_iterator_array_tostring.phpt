--TEST--
SPL RecursiveCachingIterator CALL_TOSTRING Array to string conversion (#25358)
--FILE--
<?php
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo (str_contains($m, 'Array to string conversion') ? 'array_warn' : 'other_warn')."\n";

    return true;
});

$it = new RecursiveArrayIterator([1, [2, 3]]);
$c = new RecursiveCachingIterator($it);
echo 'count='.iterator_count(new RecursiveIteratorIterator($c))."\n";
--EXPECT--
array_warn
count=3
