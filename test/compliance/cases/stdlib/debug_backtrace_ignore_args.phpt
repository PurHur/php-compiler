--TEST--
Stdlib: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) omits args (VM, #3626)
--FILE--
<?php
function inner(string $secret) {
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    echo isset($t[0]['args']) ? 'has_args' : 'no_args', "\n";
    echo $t[0]['function'], "\n";
}
function outer() {
    inner('hunter2');
}
outer();
--EXPECT--
no_args
inner
