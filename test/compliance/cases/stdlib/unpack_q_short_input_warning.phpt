--TEST--
stdlib unpack() Q short input — Zend 8.4 warning text (issue #29484)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo 'W:', $str, "\n";

    return true;
});
var_export(unpack('Q', 'xxxx'));
echo "\n";
--EXPECT--
W:unpack(): Type Q: not enough input values, need 8 values but only 4 were provided
false
