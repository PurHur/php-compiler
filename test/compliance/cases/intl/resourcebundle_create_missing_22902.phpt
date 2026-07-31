--TEST--
ResourceBundle::create missing bundle returns null + U_MISSING_RESOURCE_ERROR (#22902)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::icuAvailable()) {
    echo 'skip ICU FFI unavailable';
}
?>
--FILE--
<?php
$r = @ResourceBundle::create('xx_YY', 'ICUDATA-zone', false);
echo $r === null ? "NULL\n" : ('OBJ:'.get_class($r)."\n");
echo 'err=', intl_get_error_code(), "\n";
echo 'msg=', intl_get_error_message(), "\n";
$r2 = @ResourceBundle::create('en', 'no_such_bundle_xyz', false);
echo $r2 === null ? "NULL2\n" : ('OBJ2:'.get_class($r2)."\n");
echo 'err2=', intl_get_error_code(), "\n";
?>
--EXPECT--
NULL
err=2
msg=resourcebundle_ctor: Cannot load libICU resource bundle: U_MISSING_RESOURCE_ERROR
NULL2
err2=2
