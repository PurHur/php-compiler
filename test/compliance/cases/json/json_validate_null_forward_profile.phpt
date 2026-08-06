--TEST--
json json_validate(null) — TypeError on 8.4 forward profile (#27995, ext/json/json.c Z_PARAM_STRING)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
try {
    var_export(json_validate(null));
    echo "\nNO_TYPEERROR\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
var_export(json_validate('null'));
echo "\n";
var_export(json_validate('{bad}'));
echo "\n";
?>
--EXPECT--
TypeError
true
false
