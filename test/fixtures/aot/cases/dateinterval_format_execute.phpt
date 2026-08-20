--TEST--
AOT: DateInterval::format execute (not compile-only) after new (#32699, leftover of #7278)
--FILE--
<?php
$i = new DateInterval('P1D');
echo $i->format('%d'), "\n";
$i2 = new DateInterval('P2Y3M');
echo $i2->format('%y-%m'), "\n";
echo (new DateInterval('PT4H'))->format('%h'), "\n";
?>
--EXPECT--
1
2-3
4
--EXPECT_EXIT--
0
