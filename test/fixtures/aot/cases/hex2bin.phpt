--TEST--
AOT: hex2bin() empty, binary, and invalid input
--FILE--
<?php
echo strlen(hex2bin('')) === 0 ? 'empty' : 'bad', "\n";
echo bin2hex(hex2bin('0f0f')), "\n";
$odd = hex2bin('abc');
echo strlen($odd) === 0 ? 'odd' : 'bad', "\n";
--EXPECT--
empty
0f0f
odd
