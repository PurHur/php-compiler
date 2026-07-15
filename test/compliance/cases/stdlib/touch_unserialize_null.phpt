--TEST--
stdlib touch()/unserialize() null operand — false not TypeError (#18369, ext/standard/filestat.c, var.c)
--FILE--
<?php
var_export(touch(null));
echo "\n";
var_export(unserialize(null));
echo "\n";
?>
--EXPECT--
false
false
