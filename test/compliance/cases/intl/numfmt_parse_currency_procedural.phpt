--TEST--
numfmt_parse_currency() procedural alias (#20780)
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
?>
--EXPECT--
fn=1
method=12.5 curr=USD
proc=12.5 curr=USD
match=1
