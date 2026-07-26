--TEST--
NumberFormatter PATTERN_DECIMAL / setPattern keep fraction zeros (#22579)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$nf = new NumberFormatter('en_US', NumberFormatter::PATTERN_DECIMAL, '#,##0.00');
echo 'pattern=', $nf->getPattern(), "\n";
echo 'fmt=', $nf->format(1234.5), "\n";
echo 'fmt2=', $nf->format(1234.56), "\n";
$nf2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$nf2->setPattern('#,##0.00');
echo 'set_fmt=', $nf2->format(1234.5), "\n";
echo 'min=', $nf2->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'max=', $nf2->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";
?>
--EXPECT--
pattern=#,##0.00
fmt=1,234.50
fmt2=1,234.56
set_fmt=1,234.50
min=2
max=2
