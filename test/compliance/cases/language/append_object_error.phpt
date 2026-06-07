--TEST--
Language: append ([]=) on object throws catchable Error (#4893, zend_execute.c)
--FILE--
<?php
try {
    $o = new stdClass();
    $o[] = 1;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot use object of type stdClass as array
