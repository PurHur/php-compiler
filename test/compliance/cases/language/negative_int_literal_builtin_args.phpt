--TEST--
Language: negative integer literal tokens in builtin args (#9562, Zend/zend_compile.c)
--FILE--
<?php
var_export(intdiv(-7, 2));
echo "\n";
var_export(fmod(-5.0, 3.0));
echo "\n";
var_export(substr('hello', 0, -10));
echo "\n";
var_export(substr('hello', 1, -3));
echo "\n";
var_export(-7);
echo "\n";
?>
--EXPECT--
-3
-2.0
''
'e'
-7
