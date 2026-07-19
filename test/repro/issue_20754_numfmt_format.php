<?php
// #20754 — NumberFormatter new/format/parse + numfmt_* 
$n = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
echo 'format=', $n->format(12.5), PHP_EOL;
echo 'currency=', $n->formatCurrency(12.5, 'USD'), PHP_EOL;
echo 'parse=', var_export($n->parse('12.5'), true), PHP_EOL;
echo 'numfmt_create=', (int) function_exists('numfmt_create'), PHP_EOL;
echo 'numfmt_format=', (int) function_exists('numfmt_format'), PHP_EOL;
$p = numfmt_create('en_US', NumberFormatter::DECIMAL);
echo 'proc=', numfmt_format($p, 12.5), PHP_EOL;
