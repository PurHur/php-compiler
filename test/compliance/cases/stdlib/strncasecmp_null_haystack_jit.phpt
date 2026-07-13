--TEST--
stdlib strncasecmp() null haystack JIT — -1 not 0 (#18700)
--JIT--
--FILE--
<?php
var_export(strncasecmp(null, 'a', 1));
echo "\n";
?>
--EXPECT--
-1
