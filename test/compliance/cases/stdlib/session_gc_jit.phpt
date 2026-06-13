--TEST--
stdlib session_gc() JIT — active session GC returns zero deleted (#6006)
--FILE--
<?php
session_start();
echo (int) session_gc() === 0 ? 'gc_ok' : 'gc_fail', "\n";
--EXPECT--
gc_ok
