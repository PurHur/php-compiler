--TEST--
stdlib soundex() (#2416)
--FILE--
<?php
echo soundex('Euler'), "\n";
echo soundex('Ellery'), "\n";
echo soundex('Washington'), "\n";
echo soundex('Lee'), "\n";
echo soundex(''), "\n";
echo soundex('123'), "\n";
echo soundex("O'Clock"), "\n";
echo soundex('1Euler'), "\n";
--EXPECT--
E460
E460
W252
L000
0000
0000
O242
E460
