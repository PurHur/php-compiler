--TEST--
NumberFormatter::DURATION parse() "2:05" → 125 seconds (#25169)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::DURATION);
echo 'format=', $fmt->format(125), "\n";
$pos = 0;
$v = $fmt->parse('2:05', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'parse=';
var_export($v);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
$wired = $fmt->format(125);
$pos = 0;
$rt = $fmt->parse($wired, NumberFormatter::TYPE_DOUBLE, $pos);
echo 'roundtrip=';
var_export($rt);
echo ' pos=', $pos, "\n";
?>
--EXPECT--
format=2:05
parse=125.0 pos=4 err=0
roundtrip=125.0 pos=4
