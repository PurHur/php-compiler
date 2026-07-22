--TEST--
stdlib unpack() format repeat count INT_MAX overflow (#21884, ext/standard/pack.c)
--FILE--
<?php
$warnings = [];
set_error_handler(function ($no, $msg) use (&$warnings) {
    if (E_WARNING === $no || E_USER_WARNING === $no) {
        $warnings[] = $msg;
    }
    return true;
});
$r = unpack('a999999999999', 'x');
var_export($r);
echo "\n";
echo (isset($warnings[0]) && str_contains($warnings[0], 'integer overflow')) ? "overflow_ok\n" : "overflow_fail\n";
$r2 = unpack('a2147483647', 'x');
echo (isset($warnings[1]) && str_contains($warnings[1], 'not enough input')) ? "boundary_ok\n" : "boundary_fail\n";
--EXPECT--
false
overflow_ok
boundary_ok
