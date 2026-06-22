--TEST--
stdlib: reset()/end() inline literal by-ref Error (#10295, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

try {
    reset([]);
    echo "reset: no throw\n";
} catch (Throwable $e) {
    echo 'reset: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    end([]);
    echo "end: no throw\n";
} catch (Throwable $e) {
    echo 'end: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [1, 2];
echo 'var reset: ', reset($a), ' end: ', end($a), "\n";
--EXPECT--
reset: Error: reset(): Argument #1 ($array) cannot be passed by reference
end: Error: end(): Argument #1 ($array) cannot be passed by reference
var reset: 1 end: 2
--CREDITS--
PurHur/php-compiler issue #10295
