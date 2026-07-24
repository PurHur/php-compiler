<?php
// Repro for #22919 — INTEGER_DIGITS defaults + format pad/truncate/pattern.
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'INT=', $f->getAttribute(NumberFormatter::INTEGER_DIGITS), "\n";
echo 'MIN=', $f->getAttribute(NumberFormatter::MIN_INTEGER_DIGITS), "\n";
echo 'MAX=', $f->getAttribute(NumberFormatter::MAX_INTEGER_DIGITS), "\n";
$f->setAttribute(NumberFormatter::MIN_INTEGER_DIGITS, 4);
echo 'min4=', var_export($f->format(12), true), "\n";
$f2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f2->setAttribute(NumberFormatter::MAX_INTEGER_DIGITS, 2);
echo 'max2=', var_export($f2->format(1234), true), "\n";
$f3 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f3->setPattern('0000');
echo 'pat=', var_export($f3->format(12), true), "\n";
