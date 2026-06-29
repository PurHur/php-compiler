--TEST--
stdlib vfprintf() — TypeError when values arg is not array (#13597, ext/standard/sprintf.c)
--FILE--
<?php
try {
    vfprintf(STDOUT, '%d', 'x');
} catch (TypeError $e) {
    echo 'vfprintf: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
vfprintf: TypeError: vfprintf(): Argument #3 ($values) must be of type array, string given
