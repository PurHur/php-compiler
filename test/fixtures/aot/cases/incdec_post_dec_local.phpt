--TEST--
AOT: post-decrement on typed local updates slot (#23840)
--FILE--
<?php
$n = 5;
$n--;
echo $n;
?>
--EXPECT--
4
