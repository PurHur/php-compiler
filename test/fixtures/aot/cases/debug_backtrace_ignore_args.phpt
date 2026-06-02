--TEST--
AOT: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) accepts options (#3626)
--FILE--
<?php
function callee(string $x) {
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    echo isset($t[0]['args']) ? 'has_args' : 'no_args', "\n";
    echo $t[0]['function'], "\n";
}
callee('secret');
--EXPECT--
no_args
callee
