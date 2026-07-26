--TEST--
stdlib fdiv() rejects 3rd arg — Zend arity 2 (#23576, re-#9918, math.c)
--FILE--
<?php
declare(strict_types=1);

var_export(fdiv(10.0, 3.0));
echo "\n";
try {
    var_export(fdiv(10.0, 3.0, 1));
    echo "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
3.3333333333333335
fdiv() expects exactly 2 arguments, 3 given
