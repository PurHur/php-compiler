--TEST--
stdlib hex2bin() — E_WARNING includes function prefix (issue #17227, ext/standard/string.c)
--FILE--
<?php
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});
@hex2bin('GG');
@hex2bin('a');
--EXPECT--
W:hex2bin(): Input string must be hexadecimal string
W:hex2bin(): Hexadecimal input string must have an even length
