--TEST--
Stdlib: ini_set('user_agent') JIT/AOT (#12291)
--FILE--
<?php
declare(strict_types=1);
var_export(ini_set('user_agent', 'jit-user-agent'));
echo "\n";
var_export(ini_get('user_agent'));
echo "\n";
?>
--EXPECT--
''
'jit-user-agent'
