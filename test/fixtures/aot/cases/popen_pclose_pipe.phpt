--TEST--
AOT: popen/pclose pipe round-trip (#33430)
--FILE--
<?php
$p = popen('echo hi', 'r');
echo stream_get_contents($p);
echo pclose($p), "\n";
--EXPECT--
hi
0
