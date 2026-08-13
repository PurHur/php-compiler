--TEST--
JIT: dir() Directory factory (#30757)
--FILE--
<?php
$d = dir('/tmp');
echo get_class($d), "\n";
$e = $d->read();
echo is_string($e) ? 'read_ok' : 'read_fail', "\n";
$d->close();
echo "closed\n";
?>
--EXPECT--
Directory
read_ok
closed
