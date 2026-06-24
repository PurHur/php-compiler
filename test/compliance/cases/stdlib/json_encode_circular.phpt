--TEST--
stdlib json_encode() circular array — false + JSON_ERROR_RECURSION (issue #11181)
--FILE--
<?php
$a = [];
$a[] = &$a;
var_export(json_encode($a));
echo "\n";
echo json_last_error(), "\n";
--EXPECT--
false
6
