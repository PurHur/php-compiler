--TEST--
AOT: umask() via libc umask(2) (#3226)
--FILE--
<?php
$saved = umask();
$prev = umask(0022);
$cur = umask($saved);
echo $cur === 0022 ? "set\n" : "bad\n";
umask($saved);
echo "ok\n";
--EXPECT--
set
ok
