--TEST--
trigger_error(E_USER_ERROR) handled continues + 8.4 deprecation (#29216)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "handler errno=$errno str=$errstr\n";
    return true;
});
trigger_error('warn', E_USER_WARNING);
trigger_error('err', E_USER_ERROR);
echo "survived\n";
--EXPECT--
handler errno=512 str=warn
handler errno=8192 str=Passing E_USER_ERROR to trigger_error() is deprecated since 8.4, throw an exception or call exit with a string message instead
handler errno=256 str=err
survived
