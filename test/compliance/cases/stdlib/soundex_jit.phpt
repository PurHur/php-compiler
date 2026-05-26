--TEST--
JIT: soundex() (#2416)
--FILE--
<?php
echo soundex('Euler'), "\n";
echo soundex('Hello'), "\n";
echo soundex('Washington'), "\n";
--EXPECT--
E460
H400
W252
