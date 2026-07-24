--TEST--
AOT: pack() H/h illegal hex digit E_WARNING (issue #22831)
--FILE--
<?php
// Untyped handler — typed errno int64 is not supported for AOT JIT callbacks.
function pack_illegal_hex_aot_warn($errno, $message)
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('pack_illegal_hex_aot_warn');
var_export(bin2hex(pack('H', 'x')));
echo "\n";
var_export(bin2hex(pack('H*', '0g1')));
echo "\n";
--EXPECT--
W:pack(): Type H: illegal hex digit x
'00'
W:pack(): Type H: illegal hex digit g
'0010'
