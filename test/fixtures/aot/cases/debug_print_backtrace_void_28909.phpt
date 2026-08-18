--TEST--
AOT: debug_print_backtrace builtin executes (#28909)
--FILE--
<?php
ob_start();
debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
ob_end_clean();
echo "ok\n";
--EXPECT--
ok
