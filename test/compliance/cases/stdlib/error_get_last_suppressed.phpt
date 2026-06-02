--TEST--
Stdlib: error_get_last() after @trigger_error (issue #4381)
--FILE--
<?php
$before = error_get_last();
echo null === $before ? 'before-null' : 'before-set', "\n";

@trigger_error('boom', E_USER_WARNING);
$last = error_get_last();
echo null === error_get_last() ? 'null' : 'set', "\n";
echo $last['message'] === 'boom' ? 'msg' : 'nomsg', "\n";
echo $last['type'] === 512 ? '512' : 'type', "\n";
echo $last['line'] > 0 ? 'line' : 'noline', "\n";
--EXPECT--
before-null
set
msg
512
line
