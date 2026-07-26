--TEST--
AOT: fdiv() exactly 2 args — 3rd raises ArgumentCountError on VM path (#23576)
--FILE--
<?php
echo fdiv(5.0, 2.0), "\n";
--EXPECT--
2.5
