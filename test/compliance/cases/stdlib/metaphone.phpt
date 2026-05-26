--TEST--
stdlib metaphone() (#2423)
--FILE--
<?php
echo metaphone('program'), "\n";
echo metaphone('testing'), "\n";
echo metaphone('Washington'), "\n";
echo metaphone('Euler'), "\n";
echo metaphone(''), "\n";
echo metaphone('123'), "\n";
echo metaphone('Knight'), "\n";
echo metaphone('School'), "\n";
echo metaphone('SCI'), "\n";
echo metaphone('program', 4), "\n";
--EXPECT--
PRKRM
TSTNK
WXNKTN
ELR


NFT
SXL
S
PRKR
