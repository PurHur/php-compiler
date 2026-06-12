--TEST--
stdlib set_time_limit/ignore_user_abort/connection_aborted JIT lowering (#8078, #3242)
--FILE--
<?php
var_export(set_time_limit(0));
echo "\n";
var_export(ignore_user_abort(true));
echo "\n";
var_export(ignore_user_abort(null));
echo "\n";
var_export(connection_aborted());
echo "\n";
--EXPECT--
true
0
1
0
