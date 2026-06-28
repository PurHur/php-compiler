--TEST--
stdlib putenv() unset form — getenv() returns false not null (#13203, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$key = 'PHP_COMPILER_PUTENV_UNSET_'.(string) getmypid();
putenv($key.'=1');
putenv($key);

var_export(getenv($key));
echo "\n";
var_export(getenv($key, true));
echo "\n";
var_export(getenv($key) === false);
echo "\n";
--EXPECT--
false
false
true
