--TEST--
stdlib umask() get/set/restore (ext/standard/filestat.c parity, #3226)
--FILE--
<?php
if (!function_exists('umask')) {
    echo "missing\n";
    exit(1);
}
$saved = umask();
$prev = umask(0022);
echo $prev === $saved ? "prev\n" : "bad\n";
echo umask() === 0022 ? "new\n" : "bad\n";
$restored = umask($saved);
echo $restored === 0022 ? "set\n" : "bad\n";
umask($saved);
echo "ok\n";
--EXPECT--
prev
new
set
ok
