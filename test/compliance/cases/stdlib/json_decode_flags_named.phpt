--TEST--
stdlib json_decode() flags: named parameter skips optional slots (#10032, ext/json/php_json.c)
--FILE--
<?php
var_export(json_decode('1', flags: JSON_BIGINT_AS_STRING));
echo "\n";
var_export(json_decode('{"a":1}', associative: true, flags: 0));
--EXPECT--
1
array (
  'a' => 1,
)
