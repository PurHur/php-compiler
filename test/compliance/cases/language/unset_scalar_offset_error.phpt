--TEST--
language: unset() on scalar offset throws catchable Error (#4880, Zend/zend_execute.c)
--FILE--
<?php
$x = 1;
try {
    unset($x[0]);
    echo "unset\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot unset offset in a non-array variable
