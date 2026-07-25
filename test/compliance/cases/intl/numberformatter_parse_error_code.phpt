--TEST--
NumberFormatter::parse error is U_PARSE_ERROR (9); global intl error stays 0 (#22855)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$r = $f->parse('not-a-number');
echo 'r=', var_export($r, true), "\n";
echo 'code=', $f->getErrorCode(), "\n";
echo 'msg=', $f->getErrorMessage(), "\n";
echo 'gcode=', intl_get_error_code(), "\n";
echo 'ok=', $f->parse('1,234.5'), "\n";
echo 'ok_code=', $f->getErrorCode(), "\n";
?>
--EXPECT--
r=false
code=9
msg=Number parsing failed: U_PARSE_ERROR
gcode=0
ok=1234.5
ok_code=0
