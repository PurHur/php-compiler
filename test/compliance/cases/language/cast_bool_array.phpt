--TEST--
Language: (bool) cast on array — Zend cast (#5286)
--FILE--
<?php
var_export((bool) []);
echo "\n";
var_export((bool) [1]);
echo "\n";
?>
--EXPECT--
false
true
