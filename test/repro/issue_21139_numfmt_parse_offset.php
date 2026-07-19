<?php
// #21139 — numfmt_parse() / NumberFormatter::parse() by-ref $offset (php-src formatter_main.c)
$fmt = NumberFormatter::create('en_US', NumberFormatter::DECIMAL);

$pos = 0;
$r = numfmt_parse($fmt, '99xyz', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'proc4 r=', var_export($r, true), ' pos=', $pos, PHP_EOL;

$pos = 0;
$r = $fmt->parse('1,234.5abc', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'method r=', var_export($r, true), ' pos=', $pos, PHP_EOL;

$pos = 2;
$r = $fmt->parse('xx1,234.5yy', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'mid r=', var_export($r, true), ' pos=', $pos, PHP_EOL;

$pos = 0;
$r = $fmt->parse('abc', NumberFormatter::TYPE_DOUBLE, $pos);
echo 'fail r=', var_export($r, true), ' pos=', $pos, PHP_EOL;
