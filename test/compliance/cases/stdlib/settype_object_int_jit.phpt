--TEST--
stdlib settype() object to int JIT — E_WARNING + legacy 1 (#10690, ext/standard/type.c)
--JIT--
--FILE--
<?php
$o = new stdClass();
$ok = @settype($o, 'int');
echo $ok ? "true\n" : "false\n";
var_export($o);
echo "\n";
?>
--EXPECT--
true
1
