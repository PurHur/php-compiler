--TEST--
AOT: negative << / >> throws catchable ArithmeticError (zend_operators.c, #29751)
--FILE--
<?php
try {
    $a = 1;
    var_export($a << -1);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
    $b = 8;
    var_export($b >> -2);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
$n = -1;
try {
    var_export(4 << $n);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
--EXPECT--
ArithmeticError: Bit shift by negative number
ArithmeticError: Bit shift by negative number
ArithmeticError: Bit shift by negative number
