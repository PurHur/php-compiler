--TEST--
stdlib ignore_user_abort() — int return coerces to bool operand (#14174, ext/standard/basic_functions.c)
--FILE--
<?php
$prev = ignore_user_abort(true);
var_export($prev);
echo "\n";
var_export(ignore_user_abort($prev));
echo "\n";
echo "ok\n";
--EXPECT--
0
1
ok
