--TEST--
stdlib ini_get_all() unset ini entries report NULL not empty string (issue #17766)
--FILE--
<?php
$all = ini_get_all(null, true);
echo is_null($all['assert.callback']['global_value']) ? "global_null\n" : "global_bad\n";
echo is_null($all['assert.callback']['local_value']) ? "local_null\n" : "local_bad\n";
--EXPECT--
global_null
local_null
