--TEST--
stdlib: array_shift/array_pop/array_unshift inline literal by-ref Error (#9745, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['array_shift', 'array_pop', 'array_unshift'] as $fn) {
    try {
        if ($fn === 'array_unshift') {
            $fn([1, 2], 0);
        } else {
            $fn([1, 2]);
        }
        echo "$fn: no throw\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$a = [1, 2];
echo 'var shift: ', array_shift($a), ' remain: ', count($a), "\n";
--EXPECT--
array_shift: Error: array_shift(): Argument #1 ($array) could not be passed by reference
array_pop: Error: array_pop(): Argument #1 ($array) could not be passed by reference
array_unshift: Error: array_unshift(): Argument #1 ($array) could not be passed by reference
var shift: 1 remain: 1
--CREDITS--
PurHur/php-compiler issue #9745
