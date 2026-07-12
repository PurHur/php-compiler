--TEST--
stdlib string transform builtins — null $string TypeError (#18253, ext/standard/string.c)
--FILE--
<?php
foreach (['str_rot13', 'str_shuffle', 'str_repeat', 'hebrev'] as $fn) {
    try {
        if ('str_repeat' === $fn) {
            $fn(null, 2);
        } else {
            $fn(null);
        }
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
str_rot13(): Argument #1 ($string) must be of type string, null given
str_shuffle(): Argument #1 ($string) must be of type string, null given
str_repeat(): Argument #1 ($string) must be of type string, null given
hebrev(): Argument #1 ($string) must be of type string, null given
