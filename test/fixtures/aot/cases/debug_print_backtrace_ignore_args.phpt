--TEST--
AOT: debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) (#3314)
--FILE--
<?php
function inner() {
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
}
inner();
--EXPECTF--
#0 %s(%d): inner()
