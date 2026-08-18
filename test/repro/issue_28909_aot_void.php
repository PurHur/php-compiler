<?php
/**
 * #28909 AOT compile smoke — Reflection return is VM/JIT; this only exercises the builtin.
 */
debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
echo "ok\n";
