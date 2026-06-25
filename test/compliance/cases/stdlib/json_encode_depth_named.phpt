--TEST--
stdlib json_encode() depth: named parameter (#11492, ext/json/php_json.c)
--FILE--
<?php
var_export(json_encode([1], depth: 2));
echo "\n";
var_export(json_encode(value: [2], flags: JSON_FORCE_OBJECT));
?>
--EXPECT--
'[1]'
'{"0":2}'
