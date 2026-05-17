--TEST--
stdlib intdiv()
--FILE--
<?php
echo intdiv(17, 5), "\n";
echo intdiv(-17, 5), "\n";
echo intdiv(7, 2), "\n";
--EXPECT--
3
-3
3
