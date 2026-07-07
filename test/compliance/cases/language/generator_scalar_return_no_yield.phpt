--TEST--
Generator scalar return without yield — getReturn() value (issue #17162, Zend/zend_generators.c)
--FILE--
<?php
function g(): Generator {
    return 1;
}
$gen = g();
var_export($gen instanceof Generator);
echo "\n";
var_export($gen->getReturn());
echo "\n";
--EXPECT--
true
1
