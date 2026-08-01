--TEST--
Language: eval("class …") must not inject MCJIT class pad into the string (#26424)
--FILE--
<?php
$code = "class PadProbe26424 {}";
var_export($code);
echo "\n";
eval($code);
echo class_exists("PadProbe26424") ? "ok\n" : "no\n";
--EXPECT--
'class PadProbe26424 {}'
ok
