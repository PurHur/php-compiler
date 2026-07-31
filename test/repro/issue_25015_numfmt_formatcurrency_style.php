<?php
/**
 * NumberFormatter::formatCurrency must honor construction style (#25015).
 * php-src: ext/intl/formatter/formatter_format.c — numfmt_format_currency
 */
foreach ([
    'DECIMAL' => NumberFormatter::DECIMAL,
    'CURRENCY' => NumberFormatter::CURRENCY,
    'PERCENT' => NumberFormatter::PERCENT,
    'CURRENCY_ACCOUNTING' => NumberFormatter::CURRENCY_ACCOUNTING,
] as $name => $style) {
    $fmt = new NumberFormatter('en_US', $style);
    echo $name, ' => ', var_export($fmt->formatCurrency(12.3, 'USD'), true);
    echo ' neg=', var_export($fmt->formatCurrency(-12.3, 'USD'), true), "\n";
}
$proc = numfmt_create('en_US', NumberFormatter::DECIMAL);
echo 'proc DECIMAL => ', var_export(numfmt_format_currency($proc, 12.3, 'USD'), true), "\n";
