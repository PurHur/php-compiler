--TEST--
date strtotime(null) — false not TypeError (#18378, ext/standard/datetime.c)
--FILE--
<?php
var_export(strtotime(null));
echo "\n";
?>
--EXPECT--
false
