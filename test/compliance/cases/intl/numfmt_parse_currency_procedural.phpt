--TEST--
numfmt_parse_currency() procedural alias + optional &$offset (#20780, #21127)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip NumberFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'fn=', function_exists('numfmt_parse_currency') ? '1' : '0', "\n";
$fmt = NumberFormatter::create('en_US', NumberFormatter::CURRENCY);
$currency = null;
$method = $fmt->parseCurrency('$12.50', $currency);
echo 'method=', $method, ' curr=', $currency, "\n";
$currency = null;
$proc = numfmt_parse_currency($fmt, '$12.50', $currency);
echo 'proc=', $proc, ' curr=', $currency, "\n";
echo 'match=', (int) ($method === $proc && $currency === 'USD'), "\n";
$currency = null;
$pos = 0;
$n = numfmt_parse_currency($fmt, '$1,234.50', $currency, $pos);
echo 'offset n=', $n, ' curr=', $currency, ' pos=', $pos, "\n";
$currency = null;
$pos = 2;
$n = numfmt_parse_currency($fmt, 'xx$1,234.50yy', $currency, $pos);
echo 'mid n=', $n, ' curr=', $currency, ' pos=', $pos, "\n";
$currency = null;
$pos = 0;
$n = $fmt->parseCurrency('$12abc', $currency, $pos);
echo 'trail n=', $n, ' curr=', $currency, ' pos=', $pos, "\n";
?>
--EXPECT--
fn=1
method=12.5 curr=USD
proc=12.5 curr=USD
match=1
offset n=1234.5 curr=USD pos=9
mid n=1234.5 curr=USD pos=11
trail n=12 curr=USD pos=3
