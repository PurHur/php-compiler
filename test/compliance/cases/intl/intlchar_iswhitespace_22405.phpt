--TEST--
IntlChar::isWhitespace — ICU u_isWhitespace (#22405)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670/#22405)';
}
?>
--FILE--
<?php
echo 'method=', method_exists('IntlChar', 'isWhitespace') ? '1' : '0', "\n";
echo 'space=', IntlChar::isWhitespace(0x20) ? '1' : '0', "\n";
echo 'A=', IntlChar::isWhitespace(0x41) ? '1' : '0', "\n";
echo 'tab=', IntlChar::isWhitespace(0x09) ? '1' : '0', "\n";
echo 'nbsp=', IntlChar::isWhitespace(0xA0) ? '1' : '0', "\n";
echo 'uws_space=', IntlChar::isUWhiteSpace(0x20) ? '1' : '0', "\n";
echo 'isspace_space=', IntlChar::isspace(0x20) ? '1' : '0', "\n";
?>
--EXPECT--
method=1
space=1
A=0
tab=1
nbsp=0
uws_space=1
isspace_space=1
