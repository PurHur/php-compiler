--TEST--
AOT: eval('') returns false; ';' and whitespace remain NULL (#31914)
--FILE--
<?php
var_export(eval(''));
echo "\n";
var_export(eval(';'));
echo "\n";
var_export(eval('   '));
echo "\n";
--EXPECT--
false
NULL
NULL
