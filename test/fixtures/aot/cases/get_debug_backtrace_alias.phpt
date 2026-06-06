--TEST--
AOT: get_debug_backtrace() alias of debug_backtrace() (#6802)
--FILE--
<?php
function probe(string $x): void {
    $alias = get_debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $direct = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    echo var_export(function_exists('get_debug_backtrace')), "\n";
    echo $alias[0]['function'], "\n";
    echo isset($alias[0]['args']) ? 'has_args' : 'no_args', "\n";
    echo $alias === $direct ? 'match' : 'mismatch', "\n";
}
probe('secret');
--EXPECT--
true
probe
no_args
match
