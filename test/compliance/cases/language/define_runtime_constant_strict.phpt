--TEST--
Language: define() runtime scalar under strict_types var_export hoisted args (#17678)
--FILE--
<?php
declare(strict_types=1);
define('MY_SCALAR',42);
echo var_export(MY_SCALAR,true);
echo "\nok\n";
--EXPECT--
42
ok
