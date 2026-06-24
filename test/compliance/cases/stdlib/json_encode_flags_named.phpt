--TEST--
stdlib json_encode() flags: named parameter (#9646, ext/json/php_json.c)
--FILE--
<?php
var_export(json_encode(['a' => 1], flags: JSON_FORCE_OBJECT));
echo "\n";
var_export(json_encode(['a' => 'b'], flags: JSON_PRETTY_PRINT));
?>
--EXPECT--
'{"a":1}'
'{
    "a": "b"
}'
