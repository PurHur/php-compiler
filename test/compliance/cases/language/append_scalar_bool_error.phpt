--TEST--
Language: append ([]=) on scalar throws catchable Error (issue #4878, zend_execute.c)
--FILE--
<?php
try {
    $x = true;
    $x[] = 1;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot use a scalar value as an array
