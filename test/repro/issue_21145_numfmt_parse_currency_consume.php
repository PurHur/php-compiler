<?php
// #21145 — numfmt_parse_currency() ICU consume length (re-#21127)
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);

$curr = null;
$pos = 2;
$n = numfmt_parse_currency($fmt, 'xx$1,234.50yy', $curr, $pos);
echo 'junk n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;

$curr = null;
$pos = 0;
$n = numfmt_parse_currency($fmt, '$12abc', $curr, $pos);
echo 'trail n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;

$curr = null;
$pos = 0;
$n = numfmt_parse_currency($fmt, '$1,234.50', $curr, $pos);
echo 'full n=', var_export($n, true), ' curr=', var_export($curr, true), ' pos=', $pos, PHP_EOL;
