--TEST--
stdlib json_decode() JSON_BIGINT_AS_STRING returns digit string (ext/json/php_json.c, #12495)
--FILE--
<?php
var_export(json_decode('9999999999999999999', false, 512, JSON_BIGINT_AS_STRING));
--EXPECT--
'9999999999999999999'
