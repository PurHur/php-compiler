--TEST--
NumberFormatter format honors setAttribute/setSymbol/setTextAttribute (#21121)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::GROUPING_USED, 0);
echo 'nogroup=', $f->format(1234.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, '_');
echo 'group_sep=', $f->format(1234.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, 'POS ');
echo 'prefix=', $f->format(12), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 2);
echo 'frac=', $f->format(1), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo 'currency=', $f->format(12.5), "\n";
echo 'currency_neg=', $f->format(-12.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::GROUPING_USED, 0);
echo 'proc=', numfmt_format($f, 1234.5), "\n";
?>
--EXPECT--
nogroup=1234.5
group_sep=1_234.5
prefix=POS 12
frac=1.00
currency=$12.50
currency_neg=-$12.50
proc=1234.5
