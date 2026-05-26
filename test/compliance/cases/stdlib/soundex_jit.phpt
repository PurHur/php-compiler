--TEST--
stdlib soundex() JIT (#2416)
--FILE--
<?php
echo soundex('Euler'), "\n";
echo soundex('Washington'), "\n";
echo soundex('Pfister'), "\n";
--EXPECT--
E460
W252
P236
