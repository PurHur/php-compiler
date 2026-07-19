<?php
// #21127 — numfmt_parse_currency() optional by-ref $offset (php-src formatter.stub.php)
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
$curr = null;
$pos = 0;
$n = numfmt_parse_currency($fmt, '$1,234.50', $curr, $pos);
echo 'proc4 n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;

$curr = null;
$pos = 2;
$n = numfmt_parse_currency($fmt, 'xx$1,234.50yy', $curr, $pos);
echo 'mid n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;

$curr = null;
$n = numfmt_parse_currency($fmt, '$12.50', $curr);
echo 'proc3 n=', var_export($n, true), ' curr=', var_export($curr, true), PHP_EOL;

$curr = null;
$pos = 0;
$n = $fmt->parseCurrency('$12abc', $curr, $pos);
echo 'oop trail n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;
