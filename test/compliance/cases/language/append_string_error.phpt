--TEST--
Language: append ([]=) on string throws catchable Error with Zend message (#22651, zend_execute.c)
--FILE--
<?php
try {
    $s = 'a';
    $s[] = 'b';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: [] operator not supported for strings
