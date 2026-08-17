--TEST--
Language: eval('') returns false; ';' and whitespace remain NULL (zif_eval, #31914)
--FILE--
<?php
var_export(eval(''));
echo "\n";
var_export(eval(';'));
echo "\n";
var_export(eval('   '));
echo "\n";
$empty = '';
var_export(eval($empty));
echo "\n";
--EXPECT--
false
NULL
NULL
false
