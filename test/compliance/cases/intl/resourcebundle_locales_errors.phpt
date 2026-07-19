--TEST--
ResourceBundle getLocales/getErrorCode/getErrorMessage/getIterator (#20739)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'getLocales=', (int) method_exists(ResourceBundle::class, 'getLocales'), "\n";
$locales = ResourceBundle::getLocales('ICUDATA');
echo 'locales_ok=', (int) (is_array($locales) && count($locales) > 0), "\n";
echo 'has_en=', (int) (is_array($locales) && in_array('en', $locales, true)), "\n";
$rb = ResourceBundle::create('en', null);
echo 'err0=', $rb->getErrorCode(), "\n";
echo 'msg0=', $rb->getErrorMessage(), "\n";
$missing = $rb->get('___missing_key_zzz___');
echo 'missing=', var_export($missing, true), "\n";
echo 'err1=', $rb->getErrorCode(), "\n";
echo 'msg1=', $rb->getErrorMessage(), "\n";
$it = $rb->getIterator();
echo 'iterator=', (int) is_object($it), "\n";
echo 'getIterator=', (int) method_exists($rb, 'getIterator'), "\n";
?>
--EXPECT--
getLocales=1
locales_ok=1
has_en=1
err0=0
msg0=U_ZERO_ERROR
missing=NULL
err1=2
msg1=Cannot load resource element '___missing_key_zzz___': U_MISSING_RESOURCE_ERROR
iterator=1
getIterator=1
