--TEST--
stdlib intdiv() — ArithmeticError for PHP_INT_MIN / -1 (ext/standard/math.c, #4724)
--FILE--
<?php
try {
    intdiv(PHP_INT_MIN, -1);
    echo "no throw\n";
} catch (ArithmeticError $e) {
    echo 'ArithmeticError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
ArithmeticError: Division of PHP_INT_MIN by -1 is not an integer
