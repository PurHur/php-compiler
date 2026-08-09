--TEST--
AOT: strtr() three-arg null $from/$to — soft-null returns on 8.4 (#29308)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// DEP text guarded on VM (strtr_three_arg_null_forward84.phpt); AOT asserts coerce+return.
error_reporting(0);
echo 'from_null:'.var_export(strtr('a', null, 'x'), true)."\n";
echo 'to_null:'.var_export(strtr('a', 'a', null), true)."\n";
echo 'both_null:'.var_export(strtr('a', null, null), true)."\n";
--EXPECT--
from_null:'a'
to_null:'a'
both_null:'a'
