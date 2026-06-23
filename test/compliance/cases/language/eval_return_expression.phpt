--TEST--
language eval() return expression value as call argument (VM, issue #10661)
--FILE--
<?php
var_export(eval('return 42;'));
echo "\n";
var_export(eval('return 1 + 1;'));
echo "\n";
var_export(eval('return;'));
echo "\n";
var_export(eval('42;'));
echo "\n";
--EXPECT--
42
2
NULL
NULL
