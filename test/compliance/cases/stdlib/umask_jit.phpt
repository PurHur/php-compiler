--TEST--
stdlib umask() JIT/AOT path (#3226)
--FILE--
<?php
$saved = umask();
$prev = umask(0022);
echo $prev === $saved ? "prev\n" : "bad\n";
$cur = umask($saved);
echo $cur === 0022 ? "set\n" : "bad\n";
umask($saved);
echo "ok\n";
--EXPECT--
prev
set
ok
