--TEST--
stdlib min() and max() for two integers each
--FILE--
<?php
echo min(3, 9), "\n";
echo min(-1, 5), "\n";
echo max(3, 9), "\n";
echo max(-1, 5), "\n";
--EXPECT--
3
-1
9
5
