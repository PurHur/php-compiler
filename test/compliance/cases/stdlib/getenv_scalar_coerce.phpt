--TEST--
stdlib getenv() — scalar name coerces without caller strict_types (#4177, ext/standard/basic_functions.c)
--FILE--
<?php
putenv('1=one');
putenv('0=zero');
echo var_export(getenv(1), true), "\n";
echo var_export(getenv(0), true), "\n";
echo var_export(getenv(true), true), "\n";
echo var_export(getenv(false), true), "\n";
echo var_export(getenv(1.0), true), "\n";
?>
--EXPECT--
'one'
'zero'
'one'
false
'one'
