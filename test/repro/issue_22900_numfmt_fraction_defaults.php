<?php
// Repro for #22900 — DECIMAL/SCIENTIFIC FRACTION_* getAttribute defaults.
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'frac=', $f->getAttribute(NumberFormatter::FRACTION_DIGITS), "\n";
echo 'min=', $f->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'max=', $f->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";
echo 'fmt1.2341=', $f->format(1.2341), "\n";
$s = new NumberFormatter('en_US', NumberFormatter::SCIENTIFIC);
echo 'sci=', $s->getAttribute(NumberFormatter::FRACTION_DIGITS), '/',
    $s->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), '/',
    $s->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";
