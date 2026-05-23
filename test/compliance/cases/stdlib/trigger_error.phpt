--TEST--
stdlib trigger_error() user warning and notice (issue #1221)
--FILE--
<?php
trigger_error('warn', E_USER_WARNING);
echo "w\n";
trigger_error('note', E_USER_NOTICE);
echo "n\n";
--EXPECT--
w
n
