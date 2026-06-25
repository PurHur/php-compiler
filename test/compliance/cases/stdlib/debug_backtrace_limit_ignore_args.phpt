--TEST--
Stdlib: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit) — no {main}, call-site line (#11528)
--FILE--
<?php
function bt() {
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    echo count($t), "\n";
    echo $t[0]['function'], "\n";
    echo $t[0]['line'] > 0 ? 'line_ok' : 'line_zero', "\n";
}
bt();
--EXPECT--
1
bt
line_ok
