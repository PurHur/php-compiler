--TEST--
stdlib tmpfile() — flock and stream_supports_lock (php-src flock.c, #12813)
--FILE--
<?php
$f = tmpfile();
echo stream_supports_lock($f) ? '1' : '0', "\n";
echo flock($f, LOCK_EX | LOCK_NB) ? '1' : '0', "\n";
echo flock($f, LOCK_UN) ? '1' : '0', "\n";
echo "ok\n";
?>
--EXPECT--
1
1
1
ok
