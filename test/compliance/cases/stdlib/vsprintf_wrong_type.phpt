--TEST--
stdlib vsprintf()/vprintf() — TypeError when values arg is not array (#13589, ext/standard/sprintf.c)
--FILE--
<?php
try {
    vsprintf('%s', 'hi');
} catch (TypeError $e) {
    echo 'vsprintf: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    vprintf('%s', 'hi');
} catch (TypeError $e) {
    echo 'vprintf: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
vsprintf: TypeError: vsprintf(): Argument #2 ($values) must be of type array, string given
vprintf: TypeError: vprintf(): Argument #2 ($values) must be of type array, string given
