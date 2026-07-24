--TEST--
NumberFormatter DECIMAL/SCIENTIFIC fraction attribute defaults (#22900)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'frac=', $f->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
echo 'min=', $f->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'max=', $f->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";
echo 'fmt1.2=', $f->format(1.2), "\n";
// 1.2341 stays under max=3 without depending on ROUND_HALFEVEN (#22703).
echo 'fmt1.2341=', $f->format(1.2341), "\n";

$s = new NumberFormatter('en_US', NumberFormatter::SCIENTIFIC);
echo 'sci_frac=', $s->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
echo 'sci_min=', $s->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'sci_max=', $s->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";

$f2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f2->setAttribute(NumberFormatter::FRACTION_DIGITS, 3);
echo 'set3_fmt=', $f2->format(1.2), "\n";
echo 'set3_get=', $f2->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
echo 'set3_min=', $f2->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'set3_max=', $f2->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";

$c = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo 'cur_frac=', $c->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
$p = new NumberFormatter('en_US', NumberFormatter::PERCENT);
echo 'pct_frac=', $p->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
?>
--EXPECT--
frac=0
min=0
max=3
fmt1.2=1.2
fmt1.2341=1.234
sci_frac=0
sci_min=0
sci_max=0
set3_fmt=1.200
set3_get=3
set3_min=3
set3_max=3
cur_frac=2
pct_frac=0
