--TEST--
stdlib array_filter() mixed literal array JIT
--FILE--
<?php
$out = array_filter(['', 'ok', 0]);
echo count($out), "\n";
--EXPECT--
1
