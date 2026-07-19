<?php
// #21127 — numfmt_parse_currency() optional by-ref $offset (php-src formatter.stub.php)
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);

$curr = null;
$pos = 0;
$n = numfmt_parse_currency($fmt, '$1,234.50', $curr, $pos);
echo 'proc4 n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;

$curr = null;
$n3 = numfmt_parse_currency($fmt, '$12.50', $curr);
echo 'proc3 n=', var_export($n3, true), ' curr=', var_export($curr, true), PHP_EOL;

$curr = null;
$pos = 0;
$nm = $fmt->parseCurrency('$1,234.50', $curr, $pos);
echo 'method3 n=', var_export($nm, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;

$curr = null;
$pos = 4;
$prefixed = 'xxx $99.00';
$np = numfmt_parse_currency($fmt, $prefixed, $curr, $pos);
echo 'from_offset n=', var_export($np, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;
