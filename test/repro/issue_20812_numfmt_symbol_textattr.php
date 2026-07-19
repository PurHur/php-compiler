<?php

declare(strict_types=1);

// Issue #20812 — numfmt symbol/text_attribute procedurals.
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);
echo 'oop='.$fmt->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)."\n";
foreach ([
    'numfmt_get_symbol',
    'numfmt_set_symbol',
    'numfmt_get_text_attribute',
    'numfmt_set_text_attribute',
] as $f) {
    echo $f.'='.(function_exists($f) ? 'yes' : 'no')."\n";
}
if (function_exists('numfmt_get_symbol')) {
    echo 'proc='.numfmt_get_symbol($fmt, NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)."\n";
    numfmt_set_symbol($fmt, NumberFormatter::DECIMAL_SEPARATOR_SYMBOL, '*');
    echo 'after='.numfmt_get_symbol($fmt, NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)."\n";
    echo 'neg='.numfmt_get_text_attribute($fmt, NumberFormatter::NEGATIVE_PREFIX)."\n";
    numfmt_set_text_attribute($fmt, NumberFormatter::POSITIVE_PREFIX, 'P:');
    echo 'pos='.numfmt_get_text_attribute($fmt, NumberFormatter::POSITIVE_PREFIX)."\n";
}
