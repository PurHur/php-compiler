--TEST--
stdlib hex2bin()
--FILE--
<?php
echo strlen(hex2bin('')) === 0 ? 'empty' : 'bad', "\n";
echo bin2hex(hex2bin('0f0f')), "\n";
echo bin2hex(hex2bin('6162')), "\n";
$odd = hex2bin('abc');
echo strlen($odd) === 0 ? 'odd' : 'bad', "\n";
$badhex = hex2bin('gh');
echo strlen($badhex) === 0 ? 'badhex' : 'bad', "\n";
echo bin2hex(hex2bin('ABcd')), "\n";
--EXPECT--
empty
0f0f
6162
odd
badhex
abcd
