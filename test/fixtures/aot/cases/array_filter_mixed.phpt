--TEST--
AOT: array_filter() on mixed string/int literal array
--FILE--
<?php
$out = array_filter(['', 'ok', 0]);
echo count($out), "\n";
--EXPECT--
1
