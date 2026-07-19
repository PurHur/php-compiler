<?php
// #20780 — numfmt_parse_currency() procedural alias for NumberFormatter::parseCurrency()
echo 'fn=', function_exists('numfmt_parse_currency') ? '1' : '0', PHP_EOL;
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
$currency = null;
$method = $fmt->parseCurrency('$1,234.56', $currency);
echo 'method=', var_export($method, true), ' curr=', var_export($currency, true), PHP_EOL;
$currency = null;
$proc = numfmt_parse_currency($fmt, '$1,234.56', $currency);
echo 'proc=', var_export($proc, true), ' curr=', var_export($currency, true), PHP_EOL;
echo 'match=', (int) ($method === $proc), PHP_EOL;
