--TEST--
stdlib pack() H/h illegal hex digit E_WARNING (issue #22831, ext/standard/pack.c)
--FILE--
<?php
function pack_illegal_hex_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('pack_illegal_hex_warn_capture');
var_export(bin2hex(pack('H', 'x')));
echo "\n";
var_export(bin2hex(pack('H*', '0g1')));
echo "\n";
var_export(bin2hex(pack('h*', 'gx')));
echo "\n";
var_export(bin2hex(pack('H2', 'aG')));
echo "\n";
--EXPECT--
W:pack(): Type H: illegal hex digit x
'00'
W:pack(): Type H: illegal hex digit g
'0010'
W:pack(): Type h: illegal hex digit g
W:pack(): Type h: illegal hex digit x
'00'
W:pack(): Type H: illegal hex digit G
'a0'
