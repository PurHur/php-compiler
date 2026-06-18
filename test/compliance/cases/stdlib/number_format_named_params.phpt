--TEST--
stdlib number_format() named decimals:/decimal_separator:/thousands_separator: (#9525, ext/standard/number_format.c)
--FILE--
<?php
var_export(number_format(1.2345, decimals: 2));
echo "\n";
var_export(number_format(1234.5, decimal_separator: '.', thousands_separator: ' '));
echo "\n";
--EXPECT--
'1.23'
'1 235'
