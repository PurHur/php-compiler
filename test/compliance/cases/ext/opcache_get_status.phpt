--TEST--
ext opcache probe API — get_status false when disabled (#4421, #21755)
--FILE--
<?php
var_export(function_exists('opcache_get_status'));
echo "\n";
var_export(function_exists('opcache_get_configuration'));
echo "\n";
var_export(function_exists('opcache_reset'));
echo "\n";
$st = opcache_get_status(false);
var_export($st);
echo "\n";
var_export(is_array($st));
echo "\n";
$cfg = opcache_get_configuration();
var_export(is_array($cfg));
echo "\n";
var_export(isset($cfg['directives'], $cfg['version']));
echo "\n";
var_export($cfg['directives']['opcache.enable']);
echo "\n";
var_export(opcache_reset());
echo "\n";
?>
--EXPECT--
true
true
true
false
false
true
true
false
false
