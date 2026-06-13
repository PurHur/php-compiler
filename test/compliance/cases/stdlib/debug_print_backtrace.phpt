--TEST--
Stdlib: debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) flat output (VM, #3314)
--FILE--
<?php
function inner() {
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
}
function outer() {
    inner();
}
outer();
--EXPECTF--
#0 %s(%d): inner()
#1 %s(%d): outer()
