--TEST--
stdlib php_ini_loaded_file/get_extension_funcs Reflection |false returns (#28005, basic_functions.stub.php)
--FILE--
<?php
foreach (['php_ini_loaded_file', 'get_extension_funcs'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
}
var_export(get_extension_funcs('nosuch_ext_zzz_28005'));
echo "\n";
?>
--EXPECT--
php_ini_loaded_file return=string|false
get_extension_funcs return=array|false
false
