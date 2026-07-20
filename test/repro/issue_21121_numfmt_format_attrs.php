<?php

/**
 * Repro for #21121 — NumberFormatter::format() honors attrs/symbols/text.
 */
$f = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f->setAttribute(NumberFormatter::GROUPING_USED, 0);
var_export($f->format(1234.5));
echo PHP_EOL;
$f2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f2->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, '_');
var_export($f2->format(1234.5));
echo PHP_EOL;
$f3 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$f3->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, 'POS ');
var_export($f3->format(12));
echo PHP_EOL;
$f4 = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
var_export($f4->format(12.5));
echo PHP_EOL;
