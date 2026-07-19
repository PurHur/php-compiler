--TEST--
numfmt_get/set_symbol + get/set_text_attribute procedurals (#20812)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
foreach ([
    'numfmt_get_symbol',
    'numfmt_set_symbol',
    'numfmt_get_text_attribute',
    'numfmt_set_text_attribute',
] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo 'dec=', numfmt_get_symbol($fmt, NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
$ok = numfmt_set_symbol($fmt, NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, '*');
echo 'set=', (int) $ok, ' after=', numfmt_get_symbol($fmt, NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), "\n";
echo 'neg=', numfmt_get_text_attribute($fmt, NumberFormatter::NEGATIVE_PREFIX), "\n";
$ok2 = numfmt_set_text_attribute($fmt, NumberFormatter::POSITIVE_PREFIX, 'P:');
echo 'setText=', (int) $ok2, ' after=', numfmt_get_text_attribute($fmt, NumberFormatter::POSITIVE_PREFIX), "\n";
?>
--EXPECT--
numfmt_get_symbol=1
numfmt_set_symbol=1
numfmt_get_text_attribute=1
numfmt_set_text_attribute=1
dec=.
set=1 after=*
neg=-
setText=1 after=P:
