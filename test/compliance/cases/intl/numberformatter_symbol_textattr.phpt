--TEST--
NumberFormatter getSymbol/setSymbol/getTextAttribute/setTextAttribute (#20789)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('NumberFormatter');
foreach (['getSymbol', 'setSymbol', 'getTextAttribute', 'setTextAttribute'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? '1' : '0', "\n";
}
$f = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo 'dec=', $f->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
echo 'group=', $f->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL), "\n";
$ok = $f->setSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, '*');
echo 'setSymbol=', (int) $ok, ' after=', $f->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
echo 'neg=', $f->getTextAttribute(NumberFormatter::NEGATIVE_PREFIX), "\n";
$ok2 = $f->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, 'P:');
echo 'setText=', (int) $ok2, ' after=', $f->getTextAttribute(NumberFormatter::POSITIVE_PREFIX), "\n";
$de = NumberFormatter::create('de_DE', NumberFormatter::DECIMAL);
echo 'de_dec=', $de->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
?>
--EXPECT--
getSymbol=1
setSymbol=1
getTextAttribute=1
setTextAttribute=1
dec=.
group=,
setSymbol=1 after=*
neg=-
setText=1 after=P:
de_dec=,
