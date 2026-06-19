--TEST--
JIT: json_decode() flags: named parameter (#10032, ext/json/php_json.c)
--JIT--
--FILE--
<?php
var_export(json_decode('42', flags: JSON_BIGINT_AS_STRING));
echo "\n";
var_export(json_decode('{"k":"v"}', associative: true, flags: 0));
--EXPECT--
42
array (
  'k' => 'v',
)
