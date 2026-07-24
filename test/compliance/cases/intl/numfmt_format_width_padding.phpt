--TEST--
NumberFormatter FORMAT_WIDTH + PADDING_* honored in format() (#22920)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'WIDTH=', var_export($f->getAttribute(NumberFormatter::FORMAT_WIDTH), true), "\n";
echo 'PADPOS=', var_export($f->getAttribute(NumberFormatter::PADDING_POSITION), true), "\n";

$f->setAttribute(NumberFormatter::FORMAT_WIDTH, 8);
$f->setAttribute(NumberFormatter::PADDING_POSITION, NumberFormatter::PAD_BEFORE_PREFIX);
$f->setTextAttribute(NumberFormatter::PADDING_CHARACTER, '*');
echo 'before_prefix=', $f->format(12), "\n";

$f2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f2->setAttribute(NumberFormatter::FORMAT_WIDTH, 8);
$f2->setAttribute(NumberFormatter::PADDING_POSITION, NumberFormatter::PAD_AFTER_PREFIX);
$f2->setTextAttribute(NumberFormatter::PADDING_CHARACTER, '*');
echo 'after_prefix=', $f2->format(12), "\n";

$f3 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f3->setAttribute(NumberFormatter::FORMAT_WIDTH, 8);
$f3->setAttribute(NumberFormatter::PADDING_POSITION, NumberFormatter::PAD_BEFORE_SUFFIX);
$f3->setTextAttribute(NumberFormatter::PADDING_CHARACTER, '*');
echo 'before_suffix=', $f3->format(12), "\n";

$f4 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f4->setAttribute(NumberFormatter::FORMAT_WIDTH, 8);
$f4->setAttribute(NumberFormatter::PADDING_POSITION, NumberFormatter::PAD_AFTER_SUFFIX);
$f4->setTextAttribute(NumberFormatter::PADDING_CHARACTER, '*');
echo 'after_suffix=', $f4->format(12), "\n";

$f5 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f5->setAttribute(NumberFormatter::FORMAT_WIDTH, 8);
$f5->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, '[');
$f5->setTextAttribute(NumberFormatter::POSITIVE_SUFFIX, ']');
$f5->setAttribute(NumberFormatter::PADDING_POSITION, NumberFormatter::PAD_AFTER_PREFIX);
$f5->setTextAttribute(NumberFormatter::PADDING_CHARACTER, '*');
echo 'affix_after_prefix=', $f5->format(12), "\n";
?>
--EXPECT--
WIDTH=false
PADPOS=0
before_prefix=******12
after_prefix=******12
before_suffix=12******
after_suffix=12******
affix_after_prefix=[****12]
