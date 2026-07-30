--TEST--
NumberFormatter::SCIENTIFIC format/parse via ICU (#25162)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::SCIENTIFIC);
echo 'format=', $fmt->format(1234), "\n";
$pos = 0;
$v = $fmt->parse('1.234E3', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'parse=';
var_export($v);
echo ' pos=', $pos, "\n";
$pos = 0;
$v2 = $fmt->parse('1.234000E+3', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'parse_plus=';
var_export($v2);
echo ' pos=', $pos, "\n";
$wired = $fmt->format(1234);
$pos = 0;
$rt = $fmt->parse($wired, NumberFormatter::TYPE_DOUBLE, $pos);
echo 'roundtrip=';
var_export($rt);
echo ' pos=', $pos, "\n";
?>
--EXPECT--
format=1.234E3
parse=1234.0 pos=7
parse_plus=1234.0 pos=11
roundtrip=1234.0 pos=7
