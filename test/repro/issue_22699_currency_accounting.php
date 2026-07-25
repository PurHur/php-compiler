<?php
/** Repro #22699 — CURRENCY_ACCOUNTING formatCurrency parentheses (en_US). */
$f = new NumberFormatter('en_US', NumberFormatter::CURRENCY_ACCOUNTING);
echo 'formatCurrency=', var_export($f->formatCurrency(-12.5, 'USD'), true), "\n";
echo 'format=', var_export($f->format(-12.5), true), "\n";
echo 'pos=', var_export($f->formatCurrency(12.5, 'USD'), true), "\n";
$c = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
echo 'currency=', var_export($c->formatCurrency(-12.5, 'USD'), true), "\n";
