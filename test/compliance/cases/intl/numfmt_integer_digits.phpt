--TEST--
NumberFormatter INTEGER_DIGITS defaults + min-pad/max-truncate/pattern (#22919)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'INT=', $f->getAttribute(NumberFormatter::INTEGER_DIGITS), "\n";
echo 'MIN=', $f->getAttribute(NumberFormatter::MIN_INTEGER_DIGITS), "\n";
echo 'MAX=', $f->getAttribute(NumberFormatter::MAX_INTEGER_DIGITS), "\n";

$s = new NumberFormatter('en_US', NumberFormatter::SCIENTIFIC);
echo 'SCI=', $s->getAttribute(NumberFormatter::INTEGER_DIGITS), '/',
    $s->getAttribute(NumberFormatter::MIN_INTEGER_DIGITS), '/',
    $s->getAttribute(NumberFormatter::MAX_INTEGER_DIGITS), "\n";

$f->setAttribute(NumberFormatter::MIN_INTEGER_DIGITS, 4);
echo 'min4=', $f->format(12), "\n";

$f2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f2->setAttribute(NumberFormatter::MAX_INTEGER_DIGITS, 2);
echo 'max2=', $f2->format(1234), "\n";

$f3 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f3->setPattern('0000');
echo 'pat=', $f3->format(12), "\n";
echo 'pat_attrs=', $f3->getAttribute(NumberFormatter::INTEGER_DIGITS), '/',
    $f3->getAttribute(NumberFormatter::MIN_INTEGER_DIGITS), '/',
    $f3->getAttribute(NumberFormatter::MAX_INTEGER_DIGITS), "\n";

$f4 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f4->setAttribute(NumberFormatter::INTEGER_DIGITS, 5);
echo 'setINT5=', $f4->format(12), "\n";
echo 'setINT5_attrs=', $f4->getAttribute(NumberFormatter::INTEGER_DIGITS), '/',
    $f4->getAttribute(NumberFormatter::MIN_INTEGER_DIGITS), '/',
    $f4->getAttribute(NumberFormatter::MAX_INTEGER_DIGITS), "\n";
?>
--EXPECT--
INT=1
MIN=1
MAX=2000000000
SCI=1/1/1
min4=0,012
max2=34
pat=0012
pat_attrs=4/4/2000000000
setINT5=00,012
setINT5_attrs=5/5/5
