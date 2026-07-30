--TEST--
NumberFormatter::SPELLOUT parse() via ICU unum_parseDouble (#25161)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::SPELLOUT);
echo 'format=', $fmt->format(42), "\n";
$pos = 0;
$v = $fmt->parse('forty-two', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'parse=';
var_export($v);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
$wired = $fmt->format(42);
$pos = 0;
$rt = $fmt->parse($wired, NumberFormatter::TYPE_DOUBLE, $pos);
echo 'roundtrip=';
var_export($rt);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
?>
--EXPECT--
format=forty-two
parse=42.0 pos=9 err=0
roundtrip=42.0 pos=9 err=0
