--TEST--
AOT: metaphone() (#2423, #26794)
--FILE--
<?php
echo function_exists('metaphone') ? "yes\n" : "no\n";
echo metaphone('programming'), "\n";
echo metaphone('Knightsbridge'), "\n";
echo metaphone('Euler'), "\n";
echo metaphone('Knightsbridge', 4), "\n";
--EXPECT--
yes
PRKRMNK
NFTSBRJ
ELR
NFTS
