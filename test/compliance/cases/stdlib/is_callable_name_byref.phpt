--TEST--
stdlib is_callable() third &$callable_name argument (issue #9505)
--FILE--
<?php
$name = null;
$ok = is_callable('strlen', false, $name);
var_export($ok);
echo ' ';
var_export($name);
echo "\n";

$name = null;
$ok = is_callable('NoSuchFunction_xyz', false, $name);
var_export($ok);
echo ' ';
var_export($name);
echo "\n";
?>
--EXPECT--
true 'strlen'
false 'NoSuchFunction_xyz'
