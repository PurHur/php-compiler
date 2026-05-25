--TEST--
AOT getmypid() process ID (issue #2195)
--FILE--
<?php
$p = getmypid();
echo $p > 0 ? "pid\n" : "bad\n";
--EXPECT--
pid
