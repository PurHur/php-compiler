--TEST--
Language: empty array loose == false — [] == false (Zend zend_operators.c parity, #3657)
--FILE--
<?php
echo ([] == false) ? "true\n" : "false\n";
echo ([] === false) ? "true\n" : "false\n";
echo ([1] == false) ? "true\n" : "false\n";
echo ([] == true) ? "true\n" : "false\n";
echo ([1] == true) ? "true\n" : "false\n";
$empty = [];
echo in_array(false, [$empty], false) ? "true\n" : "false\n";
?>
--EXPECT--
true
false
false
false
true
true
