--TEST--
numfmt_parse_currency() optional by-ref $offset + ICU consume length (#21127, #21145)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
$curr = null;
$pos = 0;
$n = numfmt_parse_currency($fmt, '$1,234.50', $curr, $pos);
echo 'proc4=', $n, ' curr=', $curr, ' pos=', $pos, "\n";
$curr = null;
$n3 = numfmt_parse_currency($fmt, '$12.50', $curr);
echo 'proc3=', $n3, ' curr=', $curr, "\n";
$curr = null;
$pos = 0;
$nm = $fmt->parseCurrency('$99.00', $curr, $pos);
echo 'method=', $nm, ' curr=', $curr, ' pos=', $pos, "\n";
$curr = null;
$pos = 4;
$np = numfmt_parse_currency($fmt, 'xxx $5.50', $curr, $pos);
echo 'mid=', $np, ' curr=', $curr, ' pos=', $pos, "\n";
$curr = null;
$pos = 2;
$nj = numfmt_parse_currency($fmt, 'xx$1,234.50yy', $curr, $pos);
echo 'junk=', $nj, ' curr=', $curr, ' pos=', $pos, "\n";
$curr = null;
$pos = 0;
$nt = numfmt_parse_currency($fmt, '$12abc', $curr, $pos);
echo 'trail=', $nt, ' curr=', $curr, ' pos=', $pos, "\n";
?>
--EXPECT--
proc4=1234.5 curr=USD pos=9
proc3=12.5 curr=USD
method=99 curr=USD pos=6
mid=5.5 curr=USD pos=9
junk=1234.5 curr=USD pos=11
trail=12 curr=USD pos=3
