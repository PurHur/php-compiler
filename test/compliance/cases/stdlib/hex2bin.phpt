--TEST--
stdlib hex2bin()
--FILE--
<?php
echo strlen(hex2bin('')) === 0 ? 'empty' : 'bad', "\n";
echo bin2hex(hex2bin('0f0f')), "\n";
echo bin2hex(hex2bin('6162')), "\n";
$odd = hex2bin('abc');
echo ($odd === false) ? 'odd' : 'bad', "\n";
$badhex = hex2bin('gh');
echo ($badhex === false) ? 'badhex' : 'bad', "\n";
echo bin2hex(hex2bin('ABcd')), "\n";
--EXPECT--
empty
0f0f
6162
odd
badhex
abcd
