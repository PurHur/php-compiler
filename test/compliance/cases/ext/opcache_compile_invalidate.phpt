--TEST--
ext opcache compile/invalidate/is_script_cached disabled stubs (#23834)
--FILE--
<?php
error_reporting(E_ALL & ~E_NOTICE);
var_export(function_exists('opcache_compile_file'));
echo "\n";
var_export(function_exists('opcache_invalidate'));
echo "\n";
var_export(function_exists('opcache_is_script_cached'));
echo "\n";
$f = sys_get_temp_dir() . '/opc_compliance_' . getmypid() . '.php';
file_put_contents($f, "<?php return 1;\n");
var_export(@opcache_compile_file($f));
echo "\n";
var_export(opcache_is_script_cached($f));
echo "\n";
var_export(opcache_invalidate($f, false));
echo "\n";
var_export(opcache_invalidate(filename: $f, force: true));
echo "\n";
$r = new ReflectionFunction('opcache_compile_file');
echo $r->getParameters()[0]->getName(), "\n";
$r = new ReflectionFunction('opcache_invalidate');
echo $r->getParameters()[0]->getName(), ',', $r->getParameters()[1]->getName(), "\n";
@unlink($f);
?>
--EXPECT--
true
true
true
false
false
false
false
filename
filename,force
