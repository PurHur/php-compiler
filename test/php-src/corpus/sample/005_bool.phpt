--TEST--
sample: bool echo
--FILE--
<?php
var_export(true);
echo "\n";
var_export(false);
echo "\n";
?>
--EXPECT--
true
false
