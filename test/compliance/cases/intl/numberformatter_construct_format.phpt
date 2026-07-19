--TEST--
NumberFormatter __construct + numfmt_format/parse (#20754)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$n = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'format=', $n->format(12.5), "\n";
echo 'currency=', $n->formatCurrency(12.5, 'USD'), "\n";
echo 'parse=', $n->parse('12.5'), "\n";
echo 'fn=', (int) function_exists('numfmt_create'), ',', (int) function_exists('numfmt_format'), "\n";
$p = numfmt_create('en_US', NumberFormatter::DECIMAL);
echo 'proc=', numfmt_format($p, 12.5), "\n";
?>
--EXPECT--
format=12.5
currency=$12.50
parse=12.5
fn=1,1
proc=12.5
