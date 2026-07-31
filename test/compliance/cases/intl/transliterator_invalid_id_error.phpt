--TEST--
Transliterator::create / transliterator_create unknown ID → U_INVALID_ID (#25355)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Transliterator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$id = 'NoSuch-Rule';
$t = Transliterator::create($id);
echo $t === null ? 'null' : 'obj', "\n";
echo intl_get_error_code(), "\n";
echo intl_error_name(intl_get_error_code()), "\n";
echo intl_get_error_message(), "\n";

$t2 = transliterator_create($id);
echo $t2 === null ? 'null' : 'obj', "\n";
echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
?>
--EXPECT--
null
65569
U_INVALID_ID
transliterator_create: unable to open ICU transliterator with id "NoSuch-Rule": U_INVALID_ID
null
65569
transliterator_create: unable to open ICU transliterator with id "NoSuch-Rule": U_INVALID_ID
