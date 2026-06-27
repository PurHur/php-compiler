--TEST--
stdlib ignore_user_abort() — bool/null operands accepted (#12715, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(ignore_user_abort(false));
echo "\n";
echo ignore_user_abort(null), "\n";
echo "ok\n";
--EXPECT--
0
0
ok
