--TEST--
stdlib json_decode() JSON_OBJECT_AS_ARRAY with null $assoc JIT/AOT (#11778, ext/json/php_json.c)
--FILE--
<?php
$r = json_decode('{"a":1,"b":2}', null, 512, JSON_OBJECT_AS_ARRAY);
var_export(is_array($r));
echo "\n";
var_export($r);
echo "\n";
$r2 = json_decode('{"a":1}', false, 512, JSON_OBJECT_AS_ARRAY);
var_export(is_object($r2));
echo "\n";
--EXPECT--
true
array (
  'a' => 1,
  'b' => 2,
)
true
