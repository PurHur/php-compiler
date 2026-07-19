--TEST--
NumberFormatter formatCurrency/parse/parseCurrency + accessors (#20728)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('NumberFormatter');
foreach (['formatCurrency', 'parse', 'parseCurrency', 'getAttribute', 'setAttribute', 'getLocale', 'getErrorCode'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? '1' : '0', "\n";
}
$f = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
echo 'formatCurrency=', $f->formatCurrency(12.5, 'USD'), "\n";
$d = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo 'parse=', $d->parse('1,234.5'), "\n";
echo 'locale=', $d->getLocale(), "\n";
echo 'frac=', (int) $f->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
$curr = 'xx';
$c = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
echo 'parseCurrency=', $c->parseCurrency('$12.50', $curr), ' curr=', $curr, "\n";
?>
--EXPECT--
formatCurrency=1
parse=1
parseCurrency=1
getAttribute=1
setAttribute=1
getLocale=1
getErrorCode=1
formatCurrency=$12.50
parse=1234.5
locale=en_US
frac=2
parseCurrency=12.5 curr=USD
