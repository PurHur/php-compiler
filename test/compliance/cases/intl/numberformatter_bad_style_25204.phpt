--TEST--
NumberFormatter::create/-999 null + new throws Constructor failed (#25204)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = NumberFormatter::create('en_US', -999);
var_export($f === null);
echo "\n";
echo intl_get_error_code(), ' ', intl_get_error_message(), "\n";
try {
    new NumberFormatter('en_US', -999);
    echo "constructed\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
// Valid style still works
$ok = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo is_object($ok) ? "ok\n" : "bad\n";
echo 'ok_err=', intl_get_error_code(), "\n";
?>
--EXPECT--
true
16 numfmt_create: number formatter creation failed: U_UNSUPPORTED_ERROR
IntlException:Constructor failed
ok
ok_err=-127
