<?php
// Repro for #22921 — SIGNIFICANT_DIGITS_* defaults + format rounding.
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'USED=', var_export($f->getAttribute(NumberFormatter::SIGNIFICANT_DIGITS_USED), true), "\n";
echo 'MIN=', var_export($f->getAttribute(NumberFormatter::MIN_SIGNIFICANT_DIGITS), true), "\n";
echo 'MAX=', var_export($f->getAttribute(NumberFormatter::MAX_SIGNIFICANT_DIGITS), true), "\n";
$f->setAttribute(NumberFormatter::SIGNIFICANT_DIGITS_USED, 1);
$f->setAttribute(NumberFormatter::MIN_SIGNIFICANT_DIGITS, 2);
$f->setAttribute(NumberFormatter::MAX_SIGNIFICANT_DIGITS, 2);
echo 'sig1234=', var_export($f->format(1234), true), "\n";
echo 'sig1.234=', var_export($f->format(1.234), true), "\n";
