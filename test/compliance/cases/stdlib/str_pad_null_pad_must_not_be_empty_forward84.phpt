--TEST--
str_pad() null $pad_string ValueError under PROFILE=8.4 — must not be empty (#29755 / #29292)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    str_pad('x', 5, null);
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
ERR[8192]: str_pad(): Passing null to parameter #3 ($pad_string) of type string is deprecated
str_pad(): Argument #3 ($pad_string) must not be empty
