--TEST--
Stdlib: debug_backtrace() minimal frames (VM, #1378)
--FILE--
<?php
function callee() {
    $t = debug_backtrace();
    echo $t[0]['function'], "\n";
    echo $t[1]['function'], "\n";
    echo isset($t[0]['file']) && '' !== $t[0]['file'] ? 'file' : 'nofile', "\n";
    echo $t[0]['line'], "\n";
}
function caller() {
    callee();
}
caller();
--EXPECT--
callee
caller
file
0
