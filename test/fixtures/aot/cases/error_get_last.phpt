--TEST--
AOT: error_get_last() / error_clear_last() after trigger_error (issue #3158)
--FILE--
<?php
trigger_error('aot probe', 512);
$last = error_get_last();
echo $last['message'] === 'aot probe' ? 'warn' : 'no', "\n";
echo $last['type'] === 512 ? '512' : 'type', "\n";
error_clear_last();
$cleared = error_get_last();
echo $cleared === null ? 'cleared' : 'not', "\n";
--EXPECT--
warn
512
cleared
