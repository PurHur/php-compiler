--TEST--
stdlib extract() default EXTR_OVERWRITE replaces existing locals (#10636, ext/standard/basic_functions.c)
--FILE--
<?php
$a = 1;
extract(array('a' => 2));
var_export($a);
echo "\n";
--EXPECT--
2
