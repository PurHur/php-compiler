--TEST--
NumberFormatter::PERCENT parse() consumes % and scales /100 (#25160)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::PERCENT);
echo 'format=';
var_export($fmt->format(0.12));
echo "\n";
$pos = 0;
$v = $fmt->parse('12%', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'parse=';
var_export($v);
echo ' pos=', $pos, "\n";
$pos = 0;
$v2 = $fmt->parse('12% more', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'trail=';
var_export($v2);
echo ' pos=', $pos, "\n";
$pos = 0;
$bare = $fmt->parse('12', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'bare=';
var_export($bare);
echo ' pos=', $pos, ' err=', $fmt->getErrorCode(), "\n";
?>
--EXPECT--
format='12%'
parse=0.12 pos=3
trail=0.12 pos=3
bare=false pos=2 err=9
