--TEST--
Stdlib: debug_backtrace() limit 0 at file-level {main} — empty trace (#10484)
--FILE--
<?php
declare(strict_types=1);

echo count(debug_backtrace(0, 0)), "\n";
echo count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 0)), "\n";
--EXPECT--
0
0
