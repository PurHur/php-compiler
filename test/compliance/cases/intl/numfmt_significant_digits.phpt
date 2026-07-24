--TEST--
NumberFormatter SIGNIFICANT_DIGITS_* defaults + format rounding (#22921)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'USED=', var_export($f->getAttribute(NumberFormatter::SIGNIFICANT_DIGITS_USED), true), "\n";
echo 'MIN=', var_export($f->getAttribute(NumberFormatter::MIN_SIGNIFICANT_DIGITS), true), "\n";
echo 'MAX=', var_export($f->getAttribute(NumberFormatter::MAX_SIGNIFICANT_DIGITS), true), "\n";

$f->setAttribute(NumberFormatter::SIGNIFICANT_DIGITS_USED, 1);
$f->setAttribute(NumberFormatter::MIN_SIGNIFICANT_DIGITS, 2);
$f->setAttribute(NumberFormatter::MAX_SIGNIFICANT_DIGITS, 2);
echo 'sig1234=', $f->format(1234), "\n";
echo 'sig1.234=', $f->format(1.234), "\n";
echo 'sig12.34=', $f->format(12.34), "\n";
echo 'sig0.0123=', $f->format(0.0123), "\n";
?>
--EXPECT--
USED=0
MIN=false
MAX=false
sig1234=1,200
sig1.234=1.2
sig12.34=12
sig0.0123=0.012
