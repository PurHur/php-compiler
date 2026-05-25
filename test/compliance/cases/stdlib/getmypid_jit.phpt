--TEST--
stdlib getmypid() JIT/AOT path
--FILE--
<?php
$p = getmypid();
echo $p > 0 ? "pid\n" : "bad\n";
echo getmypid() === $p ? "stable\n" : "bad\n";
--EXPECT--
pid
stable
