--TEST--
ResourceBundle::create fallback locale sets U_USING_DEFAULT_WARNING (#22854)
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
$b = ResourceBundle::create('xx_YY', 'ICUDATA', true);
echo is_object($b) ? "object\n" : var_export($b, true)."\n";
echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
if (is_object($b)) {
    echo $b->getErrorCode(), "\n";
    echo $b->getErrorMessage(), "\n";
}
$none = ResourceBundle::create('xx_YY', 'ICUDATA', false);
echo is_object($none) ? "fallback_false=object\n" : "fallback_false=null\n";
echo 'fail_code=', (intl_get_error_code() > 0 ? 'err' : 'ok'), "\n";
?>
--EXPECT--
object
-127
U_USING_DEFAULT_WARNING
-127
U_USING_DEFAULT_WARNING
fallback_false=null
fail_code=err
