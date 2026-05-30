--TEST--
stdlib hex2bin() — E_WARNING on odd length and invalid hex (issue #3764)
--FILE--
<?php
function hex2bin_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('hex2bin_warn_capture');
$r = hex2bin('a');
var_dump($r);
$r = hex2bin('gh');
var_dump($r);
echo bin2hex(hex2bin('6162')), "\n";
--EXPECT--
W:Hexadecimal input string must have an even length
bool(false)
W:Input string must be hexadecimal string
bool(false)
6162
