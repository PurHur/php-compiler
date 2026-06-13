--TEST--
stdlib getopt() JIT lowering (#3251 phase 2)
--FILE--
<?php
var_export(getopt('x'));
echo "\n";
--EXPECT--
array (
)

