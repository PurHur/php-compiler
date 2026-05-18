--TEST--
AOT: substr() with int64 string lengths
--FILE--
<?php
echo substr('hello', 1), "\n";
echo substr('hello', 1, 3), "\n";
--EXPECT--
ello
ell
