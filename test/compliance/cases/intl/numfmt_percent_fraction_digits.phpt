--TEST--
NumberFormatter PERCENT defaults FRACTION_DIGITS to 0 (#21894)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::PERCENT);
echo 'format=', $f->format(0.456), "\n";
echo 'format2=', $f->format(0.4567), "\n";
echo 'frac=', $f->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
echo 'max=', $f->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";
echo 'min=', $f->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'proc=', numfmt_format($f, 0.456), "\n";
?>
--EXPECT--
format=46%
format2=46%
frac=0
max=0
min=0
proc=46%
