--TEST--
Stdlib: get_debug_backtrace() alias of debug_backtrace() (JIT, #6802)
--FILE--
<?php
function probe(): void {
    $alias = get_debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $direct = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    echo var_export(function_exists('get_debug_backtrace')), "\n";
    echo $alias[0]['function'], "\n";
    echo $alias === $direct ? 'match' : 'mismatch', "\n";
}
probe();
--EXPECT--
true
probe
match
