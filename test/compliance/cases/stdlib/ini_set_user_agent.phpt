--TEST--
Stdlib: ini_set('user_agent') / ini_get('user_agent') round-trip (#12291)
--FILE--
<?php
declare(strict_types=1);
var_export(ini_set('user_agent', 'php-compiler-test'));
echo "\n";
var_export(ini_get('user_agent'));
echo "\n";
?>
--EXPECT--
''
'php-compiler-test'
