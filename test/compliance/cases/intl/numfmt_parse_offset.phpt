--TEST--
numfmt_parse() / NumberFormatter::parse() optional by-ref $offset (#21139)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
$pos = 0;
$n = numfmt_parse($fmt, '99xyz', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'proc4=', $n, ' pos=', $pos, "\n";
$pos = 0;
$nm = $fmt->parse('1,234.5abc', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'method=', $nm, ' pos=', $pos, "\n";
$pos = 2;
$mid = $fmt->parse('xx1,234.5yy', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'mid=', $mid, ' pos=', $pos, "\n";
$pos = 0;
$fail = $fmt->parse('abc', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'fail=', var_export($fail, true), ' pos=', $pos, "\n";
$n3 = numfmt_parse($fmt, '12.5', NumberFormatter::TYPE_DOUBLE);
echo 'proc3=', $n3, "\n";
?>
--EXPECT--
proc4=99 pos=2
method=1234.5 pos=7
mid=1234.5 pos=9
fail=false pos=0
proc3=12.5
