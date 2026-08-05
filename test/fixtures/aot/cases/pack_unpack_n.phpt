--TEST--
AOT: pack n + unpack nval without NestedJIT OOM (#27662)
--FILE--
<?php
$b = pack("n", 258);
echo bin2hex($b), "\n";
$a = unpack("nval", $b);
echo $a["val"], "\n";
--EXPECT--
0102
258
