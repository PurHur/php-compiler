--TEST--
language: next()/prev() on call return temps — Notice + value, not Error (#25815)
--FILE--
<?php
error_reporting(E_ALL);

$ao = new ArrayObject([10, 20, 30]);

try {
    var_export(next($ao->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(prev($ao->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    next([1, 2, 3]);
    echo "literal: no throw\n";
} catch (Throwable $e) {
    echo 'literal: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [10, 20, 30];
echo 'var: ', next($a), ' key=', key($a), "\n";
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
PHP Notice:  Only variables should be passed by reference in %s on line %d
20
false
literal: Error: next(): Argument #1 ($array) could not be passed by reference
var: 20 key=1
--CREDITS--
PurHur/php-compiler issue #25815
