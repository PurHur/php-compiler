--TEST--
stdlib unpack() format repeat count INT_MAX overflow JIT (#21884)
--FILE--
<?php
$warnings = [];
set_error_handler(function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;
    return true;
});
var_export(unpack('a999999999999', 'x'));
echo "\n";
echo (isset($warnings[0]) && str_contains($warnings[0], 'integer overflow')) ? "ok\n" : "fail\n";
--EXPECT--
false
ok
