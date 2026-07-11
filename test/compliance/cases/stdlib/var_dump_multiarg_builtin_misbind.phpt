--TEST--
var_dump() with hoisted sibling builtins uses distinct arg slots (#16254)
--FILE--
<?php
$s = 'hello';
var_dump(strlen($s), substr($s, 0, 2));
?>
--EXPECT--
int(5)
string(2) "he"
