--TEST--
stream_set_blocking() toggles blocking on tmpfile() (issue #6007, php-src streams.c)
--FILE--
<?php
echo function_exists('stream_set_blocking') ? '1' : '0', "\n";
$f = tmpfile();
echo stream_set_blocking($f, true) ? '1' : '0', "\n";
echo stream_set_blocking($f, false) ? '1' : '0', "\n";
echo stream_set_blocking($f, true) ? '1' : '0', "\n";
fclose($f);
--EXPECT--
1
1
1
1
