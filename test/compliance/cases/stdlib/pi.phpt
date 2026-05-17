--TEST--
stdlib pi()
--FILE--
<?php
echo intval(pi() * 1000), "\n";
--EXPECT--
3141
