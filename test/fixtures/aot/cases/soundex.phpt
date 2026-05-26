--TEST--
AOT: soundex() (#2416)
--FILE--
<?php
echo soundex('Euler'), "\n";
echo soundex('Washington'), "\n";
echo soundex(''), "\n";
--EXPECT--
E460
W252
0000
