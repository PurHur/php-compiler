--TEST--
stdlib json_decode() int $assoc coerces to bool without strict_types (issue #11754, ext/json/php_json.c)
--FILE--
<?php
$o = json_decode('{}', 0);
echo is_object($o) ? "obj\n" : "bad_obj\n";
$a = json_decode('[]', 1);
echo is_array($a) ? "arr1\n" : "bad_arr1\n";
$b = json_decode('[]', 512);
echo is_array($b) ? "arr512\n" : "bad_arr512\n";
--EXPECT--
obj
arr1
arr512
