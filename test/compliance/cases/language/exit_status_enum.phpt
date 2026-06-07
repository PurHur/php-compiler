--TEST--
Language: ExitStatus builtin enum for exit()/die() (#7294, Zend/zend_enum.def)
--FILE--
<?php
var_export(enum_exists('ExitStatus', false));
echo "\n";
var_export(ExitStatus::Success->name);
echo "\n";
var_export(ExitStatus::Success->value);
echo "\n";
var_export(ExitStatus::Failure->value);
echo "\n";
exit(ExitStatus::Success);
--EXPECT--
true
'Success'
0
1
--EXPECT_EXIT--
0
