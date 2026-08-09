--TEST--
trigger_error(E_USER_ERROR) handled continues without 8.4 deprecation (#29216)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "handler errno=$errno str=$errstr\n";
    return true;
});
trigger_error('err', E_USER_ERROR);
echo "survived\n";
--EXPECT--
handler errno=256 str=err
survived
