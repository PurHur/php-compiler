--TEST--
AOT json_validate(null) — TypeError on 8.4 forward profile (#27995, ext/json/json.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
try {
    json_validate(null);
    echo "NO_TYPEERROR\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
echo json_validate('{}') ? '1' : '0';
echo "\n";
?>
--EXPECT--
TypeError
1
