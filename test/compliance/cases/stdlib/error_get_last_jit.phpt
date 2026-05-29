--TEST--
Stdlib: error_get_last() / error_clear_last() JIT lowering (#3158)
--FILE--
<?php
trigger_error('jit probe', 512);
$last = error_get_last();
echo $last['message'] === 'jit probe' ? 'warn' : 'no', "\n";
error_clear_last();
$cleared = error_get_last();
echo $cleared === null ? 'cleared' : 'not', "\n";
--EXPECT--
warn
cleared
