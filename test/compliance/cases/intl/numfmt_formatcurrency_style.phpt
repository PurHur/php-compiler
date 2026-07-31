--TEST--
NumberFormatter::formatCurrency honors construction style (#25015)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$dec = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo var_export($dec->formatCurrency(12.3, 'USD'), true), "\n";
$cur = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo var_export($cur->formatCurrency(12.3, 'USD'), true), "\n";
$pct = new NumberFormatter('en_US', NumberFormatter::PERCENT);
echo var_export($pct->formatCurrency(12.3, 'USD'), true), "\n";
$acc = new NumberFormatter('en_US', NumberFormatter::CURRENCY_ACCOUNTING);
echo var_export($acc->formatCurrency(-12.3, 'USD'), true), "\n";
$proc = numfmt_create('en_US', NumberFormatter::DECIMAL);
echo var_export(numfmt_format_currency($proc, 12.3, 'USD'), true), "\n";
?>
--EXPECT--
'12.3'
'$12.30'
'1,230%'
'($12.30)'
'12.3'
