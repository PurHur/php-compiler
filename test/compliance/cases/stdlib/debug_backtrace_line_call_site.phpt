--TEST--
Regression: debug_backtrace() frame line — parent call site not function decl (#14238, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
function inner(): void {
    $t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    echo $t[0]['line'], "\n";
}
inner();
--EXPECT--
7
