--TEST--
stdlib substr_replace(null) coerce on default profile JIT (#18913, ext/standard/string.c)
--JIT--
--FILE--
<?php
echo var_export(substr_replace(null, 'x', 0), true), "\n";
?>
--EXPECT--
'x'
