--TEST--
stdlib soundex() (#2416)
--FILE--
<?php
echo soundex(''), "\n";
echo soundex('Euler'), "\n";
echo soundex('Ellery'), "\n";
echo soundex('Hello'), "\n";
echo soundex('Washington'), "\n";
echo soundex('euler'), "\n";
--EXPECT--
0000
E460
E460
H400
W252
E460
