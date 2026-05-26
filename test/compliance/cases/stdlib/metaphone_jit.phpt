--TEST--
JIT: metaphone() (#2423)
--FILE--
<?php
echo metaphone('program'), "\n";
echo metaphone('Washington'), "\n";
echo metaphone('Knight'), "\n";
echo metaphone('program', 4), "\n";
--EXPECT--
PRKRM
WXNKTN
NFT
PRKR
