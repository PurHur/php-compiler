--TEST--
NumberFormatter::CURRENCY parse() requires currency affix (#25159)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
echo 'fmt=', $fmt->formatCurrency(12.5, 'USD'), "\n";
$pos = 0;
$v = $fmt->parse('$12.50', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'parse=';
var_export($v);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
$pos = 0;
$bare = $fmt->parse('12.50', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'bare=';
var_export($bare);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
$wired = $fmt->formatCurrency(12.5, 'USD');
$pos = 0;
$rt = $fmt->parse($wired, NumberFormatter::TYPE_DOUBLE, $pos);
echo 'roundtrip=';
var_export($rt);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
?>
--EXPECT--
fmt=$12.50
parse=12.5 pos=6 err=0
bare=false pos=5 err=9
roundtrip=12.5 pos=6 err=0
