--TEST--
stdlib ignore_user_abort() int coercion JIT (#12677, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
var_export(ignore_user_abort(0));
echo "\n";
var_export(ignore_user_abort(1));
echo "\n";
echo ignore_user_abort(false), "\n";
echo ignore_user_abort(null), "\n";
echo "ok\n";
--EXPECT--
0
0
1
0
ok
