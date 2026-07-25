--TEST--
NumberFormatter::formatCurrency CURRENCY_ACCOUNTING parentheses (#22699)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$a = new NumberFormatter('en_US', NumberFormatter::CURRENCY_ACCOUNTING);
echo 'acct_currency=', var_export($a->formatCurrency(-12.5, 'USD'), true), "\n";
echo 'acct_format=', var_export($a->format(-12.5), true), "\n";
echo 'acct_pos=', var_export($a->formatCurrency(12.5, 'USD'), true), "\n";
$c = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo 'cur_currency=', var_export($c->formatCurrency(-12.5, 'USD'), true), "\n";
echo 'const=', NumberFormatter::CURRENCY_ACCOUNTING, "\n";
?>
--EXPECT--
acct_currency='($12.50)'
acct_format='($12.50)'
acct_pos='$12.50'
cur_currency='-$12.50'
const=12
