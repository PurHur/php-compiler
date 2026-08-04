--TEST--
AOT: array_map(null) TypeError catchable (#27631, php-src ext/standard/array.c)
--FILE--
<?php
try {
    $r = array_map('strval', null);
    echo 'NO_THROW:'.gettype($r).':'.count($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    $r = array_map('strval', $a);
    echo 'NO_THROW:'.gettype($r).':'.count($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo implode(',', array_map('strval', [1, 2])), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:array_map(): Argument #2 ($array) must be of type array, null given
TypeError:array_map(): Argument #2 ($array) must be of type array, null given
1,2
