--TEST--
stdlib pack/unpack n — by-ref AOT path (#27662)
--FILE--
<?php
$b = pack("n", 258);
echo bin2hex($b), "\n";
$a = unpack("nval", $b);
echo $a["val"], "\n";
--EXPECT--
0102
258
