--TEST--
Stdlib: error_get_last() / error_clear_last() after trigger_error (VM, #3158)
--FILE--
<?php
trigger_error('probe warning', 512);
$last = error_get_last();
echo $last['message'] === 'probe warning' ? 'warn' : 'no', "\n";
echo $last['type'] === 512 ? '512' : 'type', "\n";
error_clear_last();
$cleared = error_get_last();
echo $cleared === null ? 'cleared' : 'not', "\n";
--EXPECT--
warn
512
cleared
