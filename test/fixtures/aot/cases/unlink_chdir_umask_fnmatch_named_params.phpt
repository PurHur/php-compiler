--TEST--
AOT: fnmatch() named pattern:/filename: arguments (#23461)
--FILE--
<?php
// umask()/chdir() AOT emit/runtime segfaults are pre-existing for positional calls too;
// fnmatch named dispatch proves BuiltinParamNames wiring on the AOT path.
var_export(fnmatch(pattern: 'a*', filename: 'abc'));
echo "\n";
var_export(fnmatch(pattern: 'b*', filename: 'abc'));
echo "\n";
--EXPECT--
true
false
