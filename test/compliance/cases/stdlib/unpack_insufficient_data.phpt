--TEST--
stdlib unpack() — insufficient data returns false + E_WARNING (issue #3775)
--FILE--
<?php
function unpack_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('unpack_warn_capture');
$r = unpack('N', 'abcd', 1);
var_dump($r);
$r2 = unpack('N', pack('N', 42));
var_dump($r2[1]);
--EXPECT--
W:unpack(): Type N: not enough input, need 4, have 3
bool(false)
int(42)

