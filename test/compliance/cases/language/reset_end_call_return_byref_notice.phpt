--TEST--
language: reset()/end() on call return temps — Notice + value, not Error (#25815)
--FILE--
<?php
error_reporting(E_ALL);

$ao = new ArrayObject([10, 20, 30]);

try {
    var_export(reset($ao->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(end($ao->getArrayCopy()));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    reset([]);
    echo "literal: no throw\n";
} catch (Throwable $e) {
    echo 'literal: ', get_class($e), ': ', $e->getMessage(), "\n";
}

$a = [10, 20, 30];
echo 'var: ', reset($a), ' key=', key($a), "\n";
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
PHP Notice:  Only variables should be passed by reference in %s on line %d
10
30
literal: Error: reset(): Argument #1 ($array) could not be passed by reference
var: 10 key=0
--CREDITS--
PurHur/php-compiler issue #25815
