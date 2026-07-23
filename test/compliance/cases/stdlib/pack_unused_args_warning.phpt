--TEST--
stdlib pack() extra value args E_WARNING unused (issue #22687, ext/standard/pack.c)
--FILE--
<?php
function pack_unused_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('pack_unused_warn_capture');
var_export(pack('a', 1, 2));
echo "\n";
var_export(bin2hex(pack('n', 1, 2, 3)));
echo "\n";
var_export(pack('a*', 'hi', 9));
echo "\n";
var_export(pack('', 1));
echo "\n";
var_export(bin2hex(pack('x2', 1)));
echo "\n";
var_export(pack('a', 1));
echo "\n";
--EXPECT--
W:pack(): 1 arguments unused
'1'
W:pack(): 2 arguments unused
'0001'
W:pack(): 1 arguments unused
'hi'
W:pack(): 1 arguments unused
''
W:pack(): 1 arguments unused
'0000'
'1'
