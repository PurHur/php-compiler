--TEST--
stdlib substr() with offset and length
--FILE--
<?php
echo substr('hello', 1), "\n";
echo substr('hello', 1, 3), "\n";
echo substr('hello', 10), "\n";
echo substr('hello', 0, 0), "\n";
--EXPECT--
ello
ell

