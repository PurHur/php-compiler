--TEST--
numfmt_create("en_US") sets U_USING_DEFAULT_WARNING (#23547)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::icuAvailable()) {
    echo 'skip ICU FFI unavailable';
}
?>
--FILE--
<?php
$fmt = @numfmt_create('en_US', NumberFormatter::DECIMAL);
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";
var_export($fmt instanceof NumberFormatter);
echo "\n";
$fmt2 = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";
var_export($fmt2 instanceof NumberFormatter);
echo "\n";
?>
--EXPECT--
-127|U_USING_DEFAULT_WARNING
true
-127|U_USING_DEFAULT_WARNING
true
