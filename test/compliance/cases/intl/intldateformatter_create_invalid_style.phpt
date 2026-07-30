--TEST--
IntlDateFormatter::create/__construct illegal styles → null / IntlException (#25205)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = IntlDateFormatter::create('en_US', -999, -999);
echo 'create_null=', ($f === null) ? 'yes' : 'no', "\n";
echo 'create_code=', intl_get_error_code(), "\n";
echo 'create_msg=', intl_get_error_message(), "\n";

$f2 = datefmt_create('en_US', 4, IntlDateFormatter::NONE);
echo 'date_style_null=', ($f2 === null) ? 'yes' : 'no', "\n";
echo 'date_style_msg=', intl_get_error_message(), "\n";

$f3 = datefmt_create('en_US', IntlDateFormatter::NONE, 4);
echo 'time_style_null=', ($f3 === null) ? 'yes' : 'no', "\n";
echo 'time_style_msg=', intl_get_error_message(), "\n";

$ok = IntlDateFormatter::create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
echo 'valid=', ($ok instanceof IntlDateFormatter) ? 'yes' : 'no', "\n";
echo 'valid_code=', intl_get_error_code(), "\n";

try {
    new IntlDateFormatter('en_US', -999, -999);
    echo "ctor=ok\n";
} catch (IntlException $e) {
    echo 'ctor=', $e->getMessage(), "\n";
}
?>
--EXPECT--
create_null=yes
create_code=1
create_msg=datefmt_create: invalid date format style: U_ILLEGAL_ARGUMENT_ERROR
date_style_null=yes
date_style_msg=datefmt_create: invalid date format style: U_ILLEGAL_ARGUMENT_ERROR
time_style_null=yes
time_style_msg=datefmt_create: invalid time format style: U_ILLEGAL_ARGUMENT_ERROR
valid=yes
valid_code=0
ctor=datefmt_create: invalid date format style: U_ILLEGAL_ARGUMENT_ERROR
