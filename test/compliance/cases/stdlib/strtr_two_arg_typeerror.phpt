--TEST--
stdlib strtr() two-arg TypeError uses $from label (#16772, ext/standard/string.c)
--FILE--
<?php
try {
    strtr('abc', 1);
    echo "int uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    strtr('abc', new stdClass());
    echo "object uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
strtr(): Argument #2 ($from) must be of type array, string given
strtr(): Argument #2 ($from) must be of type array|string, stdClass given
