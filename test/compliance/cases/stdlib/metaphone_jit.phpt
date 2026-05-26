--TEST--
JIT: metaphone() (#2423)
--FILE--
<?php
echo metaphone('Knightsbridge'), "\n";
echo metaphone('Euler'), "\n";
echo metaphone('Knightsbridge', 4), "\n";
--EXPECT--
NFTSBRJ
ELR
NFTS
