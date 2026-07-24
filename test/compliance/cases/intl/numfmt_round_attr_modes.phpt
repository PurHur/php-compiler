--TEST--
NumberFormatter ROUNDING_MODE honored in format() (#22703)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$def = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'def=', $def->getAttribute(NumberFormatter::ROUNDING_MODE), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_HALFEVEN;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'HALFEVEN=', $f->format(1.5), '|', $f->format(2.5), '|', $f->format(3.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_HALFDOWN;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'HALFDOWN=', $f->format(1.5), '|', $f->format(2.5), '|', $f->format(3.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_HALFUP;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'HALFUP=', $f->format(1.5), '|', $f->format(2.5), '|', $f->format(3.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_CEILING;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'CEILING=', $f->format(1.1), '|', $f->format(-1.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_FLOOR;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'FLOOR=', $f->format(1.9), '|', $f->format(-1.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_DOWN;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'DOWN=', $f->format(1.5), '|', $f->format(2.5), '|', $f->format(3.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
$mode = NumberFormatter::ROUND_UP;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'UP=', $f->format(1.5), '|', $f->format(2.5), '|', $f->format(3.5), "\n";

$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 1);
$f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);
$mode = NumberFormatter::ROUND_HALFEVEN;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'frac1_he=', $f->format(1.25), '|', $f->format(1.35), "\n";
$mode = NumberFormatter::ROUND_FLOOR;
$f->setAttribute(NumberFormatter::ROUNDING_MODE, $mode);
echo 'frac1_fl=', $f->format(1.29), '|', $f->format(-1.21), "\n";
?>
--EXPECT--
def=4
HALFEVEN=2|2|4
HALFDOWN=1|2|3
HALFUP=2|3|4
CEILING=2|-1
FLOOR=1|-2
DOWN=1|2|3
UP=2|3|4
frac1_he=1.2|1.4
frac1_fl=1.2|-1.3
