--TEST--
instanceof iterable — arrays are not objects; Zend returns false (#4754)
--FILE--
<?php
var_export([1, 2] instanceof iterable);
echo "\n";
?>
--EXPECT--
false
