--TEST--
stdlib json_decode() int $assoc coerces to bool without strict_types JIT (issue #11754)
--JIT--
--FILE--
<?php
$o = json_decode('{}', 0);
echo is_object($o) ? "obj\n" : "bad_obj\n";
$a = json_decode('[]', 1);
echo is_array($a) ? "arr1\n" : "bad_arr1\n";
--EXPECT--
obj
arr1
