--TEST--
AOT: ternary as non-first comma-echo argument prints branch string (#23915)
--FILE--
<?php
$s = 5.0;
echo "sum=", ($s > 1000000) ? "escaped" : "bounded", "\n";
$color = 0;
echo $color == 0 ? "_" : "#", "\n";
$n = 5;
echo "sum=", ($n > 1000000) ? "escaped" : "bounded", "\n";
--EXPECT--
sum=bounded
_
sum=bounded
