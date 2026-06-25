--TEST--
stdlib iconv() — UTF-8//IGNORE strips invalid UTF-8 bytes (#11678)
--FILE--
<?php
$s = iconv('UTF-8', 'UTF-8//IGNORE', "a\xc0b");
echo 'hex=', bin2hex($s), "\n";
echo 'len=', strlen($s), "\n";
--EXPECT--
hex=6162
len=2
