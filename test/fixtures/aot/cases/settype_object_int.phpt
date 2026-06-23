--TEST--
AOT settype() object to int — E_WARNING + legacy 1 (#10690, ext/standard/type.c)
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
