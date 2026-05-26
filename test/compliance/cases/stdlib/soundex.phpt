--TEST--
stdlib soundex() (#2416)
--FILE--
<?php
echo soundex(''), "\n";
echo soundex('Euler'), "\n";
echo soundex('Ellison'), "\n";
echo soundex('Gauss'), "\n";
echo soundex('Hilbert'), "\n";
echo soundex('Knuth'), "\n";
echo soundex('Lloyd'), "\n";
echo soundex('Lukasiewicz'), "\n";
echo soundex('123'), "\n";
echo soundex('Washington'), "\n";
echo soundex('Jackson'), "\n";
echo soundex('test'), "\n";
echo soundex('1abc'), "\n";
echo soundex('a'), "\n";
echo soundex('Pfister'), "\n";
--EXPECT--
0000
E460
E425
G200
H416
K530
L300
L222
0000
W252
J250
T230
A120
A000
P236
