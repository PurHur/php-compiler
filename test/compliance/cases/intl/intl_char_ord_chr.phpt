--TEST--
IntlChar::ord/chr Unicode code points (#6171)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlChar withheld until extension_loaded(\'intl\') (#19670/#6171)';
}
?>
--FILE--
<?php
echo 'intlchar=', (int) class_exists('IntlChar', false), "\n";
echo 'ord_A=', IntlChar::ord('A'), "\n";
echo 'chr_65=', IntlChar::chr(65), "\n";
echo 'ord_eacute=', IntlChar::ord("\xC3\xA9"), "\n";
echo 'chr_233=', bin2hex(IntlChar::chr(233)), "\n";
echo 'ord_emoji=', IntlChar::ord("\xF0\x9F\x98\x80"), "\n";
echo 'ord_multi=', var_export(IntlChar::ord('AB'), true), "\n";
echo 'chr_oob=', var_export(IntlChar::chr(0x110000), true), "\n";
echo 'prop_ws=', IntlChar::PROPERTY_WHITE_SPACE, "\n";
?>
--EXPECT--
intlchar=1
ord_A=65
chr_65=A
ord_eacute=233
chr_233=c3a9
ord_emoji=128512
ord_multi=NULL
chr_oob=NULL
prop_ws=31
