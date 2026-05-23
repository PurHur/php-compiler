--TEST--
AOT: instanceof for user-defined classes (#138)
--FILE--
<?php
class Box {}
class Other {}
$o = new Box();
echo ($o instanceof Box) ? "yes\n" : "no\n";
echo ($o instanceof Other) ? "yes\n" : "no\n";
--EXPECT--
yes
no
--EXPECT_EXIT--
0
