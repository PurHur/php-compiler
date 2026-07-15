--TEST--
stdlib ord() — strict_types rejects float operand (issue #19040, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

try {
    ord(65.9);
    echo "ord_float: ok\n";
} catch (Throwable $e) {
    echo 'ord_float:', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
ord_float:TypeError:ord(): Argument #1 ($character) must be of type string, float given
