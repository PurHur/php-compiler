--TEST--
AOT trigger_error() user warning (issue #1221)
--FILE--
<?php
trigger_error('aot-warn', E_USER_WARNING);
echo "ok\n";
--EXPECT--
ok
