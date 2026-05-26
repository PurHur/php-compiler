--TEST--
stdlib metaphone() (#2423)
--FILE--
<?php
echo metaphone('Knightsbridge'), "\n";
echo metaphone('Ellery'), "\n";
echo metaphone('programming'), "\n";
echo metaphone('Washington'), "\n";
echo metaphone(''), "\n";
echo metaphone('123'), "\n";
echo metaphone('Euler'), "\n";
echo metaphone('scheme'), "\n";
echo metaphone('Knightsbridge', 4), "\n";
--EXPECT--
NFTSBRJ
ELR
PRKRMNK
WXNKTN

ELR
SXM
NFTS
