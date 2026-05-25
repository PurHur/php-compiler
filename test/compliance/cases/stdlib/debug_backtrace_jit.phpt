--TEST--
Stdlib: debug_backtrace() minimal frames (JIT, #1378, #1870, #1056)
--FILE--
<?php
function callee() {
    $t = debug_backtrace();
    echo $t[0]['function'], "\n";
    echo $t[1]['function'], "\n";
    echo isset($t[0]['file']) && '' !== $t[0]['file'] ? 'file' : 'nofile', "\n";
    echo $t[0]['line'], "\n";
}
callee();
--EXPECT--
debug_backtrace
callee
file
0
