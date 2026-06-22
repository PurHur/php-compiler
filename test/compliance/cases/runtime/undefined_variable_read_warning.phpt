--TEST--
Runtime: undefined variable E_WARNING on read — casts/unary/arithmetic/?? RHS (Zend/zend_execute.c, #10358)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

var_export(+$x);
echo "\n";
var_export($x + 1);
echo "\n";
var_export((string)$x);
echo "\n";
var_export($x ?? $y);
echo "\n";
echo isset($x) ? 'isset' : 'not', "\n";
echo empty($x) ? 'empty' : 'not', "\n";
--EXPECT--
W:Undefined variable $x
0
W:Undefined variable $x
1
W:Undefined variable $x
''
W:Undefined variable $y
NULL
not
empty
