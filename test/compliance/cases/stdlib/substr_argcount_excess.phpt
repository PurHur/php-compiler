--TEST--
stdlib substr() excess positional args (#17252 / #27749, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

try {
    substr('abc', 0, 1, 99);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
substr() expects at most 3 arguments, 4 given
