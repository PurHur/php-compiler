--TEST--
stdlib substr_replace(null $replace) coerce on default profile (#18956, ext/standard/string.c)
--FILE--
<?php
echo var_export(substr_replace('hello', null, 0), true), "\n";
?>
--EXPECT--
''
