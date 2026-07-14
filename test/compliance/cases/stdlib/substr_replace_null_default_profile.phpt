--TEST--
stdlib substr_replace(null) coerce on default profile (#18913, ext/standard/string.c)
--FILE--
<?php
echo var_export(substr_replace(null, 'x', 0), true), "\n";
?>
--EXPECT--
'x'
